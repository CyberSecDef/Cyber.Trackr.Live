<?php

namespace App\Service;

use Symfony\Component\HttpFoundation\StreamedResponse;
use ZipStream\ZipStream;

/**
 * Turns a user's bulk-download selection into a sequence of ≤500 MB chunks
 * and streams each chunk as a ZIP without buffering it to disk or RAM.
 *
 * Stateless: the client receives a plan, then re-sends each chunk's file
 * list when it wants the bytes. We re-validate every entry against the
 * bulk-download sidecar index, so a client can't trick us into streaming
 * anything that isn't a real STIG XML or DISA companion ZIP.
 */
class BulkDownloadPlanner
{
    public const CHUNK_LIMIT_BYTES = 500 * 1024 * 1024;
    public const KIND_XML = 'xml';
    public const KIND_ZIP = 'zip';

    public function __construct(private BulkDownloadIndex $index) {}

    /**
     * Validate + resolve a raw selection (kind+id pairs from the wire)
     * into concrete file descriptors backed by the sidecar index.
     *
     * @param  array<int,array{kind:string,id:string}>  $selections
     * @return array<int,array{kind:string,id:string,path:string,name:string,size:int}>
     */
    public function resolve(array $selections): array
    {
        $resolved = [];
        $seenSel  = [];     // dedupe by kind+id (user-side dup)
        $seenPath = [];     // dedupe by resolved path (e.g. multiple STIGs sharing one DISA bundle)
        foreach ($selections as $sel) {
            $kind = $sel['kind'] ?? '';
            $id   = $sel['id']   ?? '';
            if ($kind !== self::KIND_XML && $kind !== self::KIND_ZIP) continue;
            if (BulkDownloadIndex::splitId($id) === null)             continue;
            $selKey = $kind . '::' . $id;
            if (isset($seenSel[$selKey])) continue;
            $seenSel[$selKey] = true;

            $row = $this->index->get($id);
            if ($row === null) continue;

            if ($kind === self::KIND_XML) {
                if (($row['xml_size'] ?? 0) <= 0) continue;
                $path = $this->index->stigDir() . '/' . $row['xml_filename'];
                if (!is_file($path) || isset($seenPath[$path])) continue;
                $seenPath[$path] = true;
                $resolved[] = [
                    'kind' => self::KIND_XML,
                    'id'   => $id,
                    'path' => $path,
                    'name' => $row['xml_filename'],
                    'size' => (int) $row['xml_size'],
                ];
            } else {
                if (($row['zip_size'] ?? 0) <= 0 || ($row['zip_filename'] ?? null) === null) continue;
                $path = $this->index->zipDir() . '/' . $row['zip_filename'];
                if (!is_file($path) || isset($seenPath[$path])) continue;
                $seenPath[$path] = true;
                $resolved[] = [
                    'kind' => self::KIND_ZIP,
                    'id'   => $id,
                    'path' => $path,
                    'name' => $row['zip_filename'],
                    'size' => (int) $row['zip_size'],
                ];
            }
        }
        return $resolved;
    }

    /**
     * Greedy bin-pack a resolved selection into ≤500 MB chunks. A single
     * file larger than the cap gets its own chunk (oversized but
     * unavoidable — DISA bundles can exceed 500 MB on their own).
     *
     * Each returned chunk has its `files` shaped for re-sending to
     * streamChunk(): same {kind,id} pairs the client originally sent.
     *
     * @param  array<int,array{kind:string,id:string,path:string,name:string,size:int}> $resolved
     * @return array{chunks:array<int,array<string,mixed>>,total_files:int,total_bytes:int}
     */
    public function plan(array $resolved): array
    {
        $chunks = [];
        $current = ['files' => [], 'bytes' => 0];

        $flush = function () use (&$chunks, &$current) {
            if ($current['files']) {
                $chunks[] = $current;
                $current = ['files' => [], 'bytes' => 0];
            }
        };

        foreach ($resolved as $r) {
            $entry = [
                'kind' => $r['kind'],
                'id'   => $r['id'],
                'name' => $r['name'],
                'size' => $r['size'],
            ];
            // If adding this file would push us over the cap AND the
            // current chunk has at least one file, start a new chunk.
            if ($current['bytes'] > 0 && $current['bytes'] + $r['size'] > self::CHUNK_LIMIT_BYTES) {
                $flush();
            }
            $current['files'][] = $entry;
            $current['bytes']  += $r['size'];
        }
        $flush();

        // Stamp chunk_index + of for the UI.
        $total = count($chunks);
        $totalFiles = 0;
        $totalBytes = 0;
        foreach ($chunks as $i => &$c) {
            $c['index'] = $i + 1;
            $c['of']    = $total;
            $totalFiles += count($c['files']);
            $totalBytes += $c['bytes'];
        }
        unset($c);

        return [
            'chunks'      => $chunks,
            'total_files' => $totalFiles,
            'total_bytes' => $totalBytes,
        ];
    }

    /**
     * Stream the given resolved files as a single ZIP. Caller is
     * responsible for having already validated the selection via
     * resolve(). Filename for the response is the supplied $zipName.
     */
    public function streamChunk(array $resolved, string $zipName): StreamedResponse
    {
        $response = new StreamedResponse(function () use ($resolved, $zipName) {
            $zip = new ZipStream(
                outputName: $zipName,
                sendHttpHeaders: false,
                defaultEnableZeroHeader: true,
            );
            foreach ($resolved as $f) {
                $fh = fopen($f['path'], 'rb');
                if ($fh === false) continue;
                // Prefix XML and ZIP into separate subfolders so a mixed
                // selection extracts tidily on the user's end.
                $arcName = ($f['kind'] === self::KIND_XML ? 'stig/' : 'zips/') . $f['name'];
                $zip->addFileFromStream(fileName: $arcName, stream: $fh);
                fclose($fh);
            }
            $zip->finish();
        });
        $response->headers->set('Content-Type', 'application/zip');
        $response->headers->set(
            'Content-Disposition',
            'attachment; filename="' . addslashes($zipName) . '"'
        );
        // No Content-Length: we don't know it in advance with streamed
        // deflate. Browsers handle it fine.
        $response->headers->set('X-Accel-Buffering', 'no'); // disable nginx buffering
        return $response;
    }
}
