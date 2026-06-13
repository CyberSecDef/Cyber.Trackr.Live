<?php

namespace App\Controller;

use Symfony\Component\Routing\Attribute\Route;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

class CciController extends AbstractController
{
    private readonly string $dataDir;

    public function __construct(?string $dataDir = null)
    {
        // Base path for resources/data. Null in production -> bundled location;
        // tests pass a dir missing the global files to exercise the 503 guards.
        $this->dataDir = $dataDir ?? __DIR__ . '/../../resources/data';
    }

    /**
     * The CCI page shell. The table is populated client-side from /cci/data (DataTables
     * ajax + deferRender) — there are ~5,100 CCIs, so server-rendering every row into the
     * HTML produced a multi-megabyte page and a 5,100-node DOM. The shell is tiny.
     */
    #[Route('/cci', name: 'cci')]
    public function cci(): Response
    {
        return $this->render('cci/index.html.twig');
    }

    /**
     * Row data for the CCI table, as JSON. Returns {data: [...rows], controls: {id: {...}}}.
     * Per-control text (name/definition/guidance) is sent ONCE in `controls` and referenced
     * by each row's `ctrl` id, rather than repeated on every CCI that maps to it.
     */
    #[Route('/cci/data', name: 'cci_data')]
    public function cciData(): JsonResponse
    {
        return new JsonResponse($this->buildRows());
    }

    /**
     * Build the revision-aware CCI rows + the deduplicated control map.
     *
     * Defaults to Rev 5. The 2024 CCI list carries both Rev 4 (version="4") and Rev 5
     * (version="5") 800-53 control references per CCI, which drives the display:
     *  - Rev 5 mapping present -> Rev 5 control(s) live; any DIFFERING Rev 4 control struck.
     *  - Rev 4 mapping only     -> dropped in Rev 5; Rev 4 control struck.
     *  - Rev 3 (legacy) only    -> only the original 800-53 referenced it; resolve the
     *                              control against Rev 5 and flag it as legacy-derived.
     *  - no 800-53 control      -> nothing shown for the control.
     * Control verbiage is Rev 5 when available (conflicting verbiage -> Rev 5), else the
     * Rev 4 text for a dropped control. The DoD per-CCI assessment overlay (AP acronym +
     * auditor/org guidance) has no public Rev 5 equivalent, so the Rev 4 text is carried
     * forward for any CCI still mapped under Rev 4.
     *
     * @return array{data: array<int, array<string, mixed>>, controls: array<string, array<string, mixed>>}
     */
    private function buildRows(): array
    {
        $cci_path = realpath($this->dataDir . "/cci/U_CCI_List_2024.xml");
        if ($cci_path === false || !is_file($cci_path)) {
            throw new ServiceUnavailableHttpException(null, 'CCI data file is not available.');
        }
        $cci_xml = simplexml_load_file($cci_path);
        if ($cci_xml === false) {
            throw new ServiceUnavailableHttpException(null, 'CCI data file could not be parsed.');
        }
        foreach ($cci_xml->getDocNamespaces() as $strPrefix => $strNamespace) {
            if (strlen((string) $strPrefix) == 0) {
                $strPrefix = "a";
            }
            $cci_xml->registerXPathNamespace($strPrefix, $strNamespace);
        }
        $cci_xml->registerXPathNamespace("xmlns", "http://iase.disa.mil/cci");

        // Rev 5 catalog (controls + 800-53A objectives) and Rev 4 catalog (+ the DoD
        // per-CCI overlay). Supplementary — missing files degrade enrichment, not 503.
        $r5 = $this->loadControlCatalog($this->dataDir . "/800-53r5.json");
        $r4 = [];
        $r4_dod = [];
        $r4_path = realpath($this->dataDir . "/800-53r4.json");
        if ($r4_path !== false && is_file($r4_path)) {
            foreach (json_decode(file_get_contents($r4_path)) ?: [] as $c) {
                $r4[$this->canonControl($c->id)] = $c;
                foreach ($c->ccis ?? [] as $cc) {
                    $r4_dod[$cc->id] = $cc;
                }
            }
        }

        $rows = [];
        $controls = []; // dedup map: control id => {name, guidance, definition, withdrawn}
        foreach ($cci_xml->xpath('//xmlns:cci_item') as $cci) {
            $id = (string) $cci->attributes()['id'];
            $status = strtolower((string) $cci->status);

            $r5ctrls = [];
            $r4ctrls = [];
            $r3ctrls = [];
            if (isset($cci->references)) {
                foreach ($cci->references->reference as $ref) {
                    $ver = (string) $ref->attributes()['version'];
                    $base = $this->baseControlFromIndex((string) $ref->attributes()['index']);
                    if ($base === null) {
                        continue;
                    }
                    if ($ver === '5') {
                        $r5ctrls[$base] = true;
                    } elseif ($ver === '4') {
                        $r4ctrls[$base] = true;
                    } elseif ($ver === '3') {
                        $r3ctrls[$base] = true;
                    }
                }
            }
            $r5ctrls = array_keys($r5ctrls);
            $r4ctrls = array_keys($r4ctrls);
            $r3ctrls = array_keys($r3ctrls);

            if (!empty($r5ctrls)) {
                $rev = '5';
                $live = $r5ctrls;
                $struck = array_values(array_diff($r4ctrls, $r5ctrls));
            } elseif (!empty($r4ctrls)) {
                $rev = '4';
                $live = [];
                $struck = $r4ctrls;
            } elseif (!empty($r3ctrls)) {
                // Legacy: only the original 800-53 (version 3) referenced this CCI. The
                // base-control numbering carried into Rev 5 for these, so resolve the
                // text against the Rev 5 catalog and flag the row as legacy-derived.
                $rev = '3';
                $live = $r3ctrls;
                $struck = [];
            } else {
                $rev = 'none';
                $live = [];
                $struck = [];
            }

            // Resolve the primary control's text into the dedup map (once per control).
            $primary = $live[0] ?? ($struck[0] ?? null);
            if ($primary !== null && !array_key_exists($primary, $controls)) {
                if (isset($r5[$primary])) {
                    $c = $r5[$primary];
                    $controls[$primary] = [
                        'name' => $c->name ?? '',
                        'guidance' => $c->guidance ?? '',
                        'definition' => $c->definition ?? '',
                        'withdrawn' => (($c->status ?? 'active') === 'withdrawn'),
                    ];
                } elseif (isset($r4[$primary])) {
                    $c = $r4[$primary];
                    $controls[$primary] = [
                        'name' => $c->name ?? '',
                        'guidance' => $c->guidance ?? '',
                        'definition' => $c->definition ?? '',
                        'withdrawn' => false,
                    ];
                } else {
                    $controls[$primary] = ['name' => '', 'guidance' => '', 'definition' => '', 'withdrawn' => false];
                }
            }

            $rows[] = [
                'cci' => $id,
                'cci_definition' => (string) $cci->definition,
                'cci_auditor' => isset($r4_dod[$id]) ? ($r4_dod[$id]->guidance_auditor ?? '') : '',
                'cci_guidance' => isset($r4_dod[$id]) ? ($r4_dod[$id]->guidance_org ?? '') : '',
                'ap_acronym' => isset($r4_dod[$id]) ? ($r4_dod[$id]->ap_acronym ?? '') : '',
                'rev' => $rev,
                'deprecated' => ($status === 'deprecated'),
                'controls' => $live,        // Rev 5 controls (linked, live)
                'controls_struck' => $struck, // Rev-4-only / superseded (struck)
                'ctrl' => $primary,         // key into `controls` for name/text
            ];
        }

        return ['data' => $rows, 'controls' => $controls];
    }

    /** Load a control-catalog JSON keyed by canonical control id. Empty array if absent. */
    private function loadControlCatalog(string $path): array
    {
        $real = realpath($path);
        $out = [];
        if ($real !== false && is_file($real)) {
            foreach (json_decode(file_get_contents($real)) ?: [] as $c) {
                $out[$this->canonControl($c->id)] = $c;
            }
        }
        return $out;
    }

    /**
     * Base 800-53 control id from a CCI reference index.
     * e.g. "AC-2 (3) a 1" -> "AC-2(3)", "AC-1 a" -> "AC-1". Null when no control token.
     */
    private function baseControlFromIndex(string $index): ?string
    {
        if (preg_match('/^\s*([A-Z]{2}-\d+(?:\s*\(\d+\))?)/', $index, $m)) {
            return $this->canonControl($m[1]);
        }
        return null;
    }

    /** Canonical control id: trimmed, whitespace-stripped, upper-case. "AC-11 (1)" -> "AC-11(1)". */
    private function canonControl(string $id): string
    {
        return strtoupper(str_replace(' ', '', trim($id)));
    }
}
