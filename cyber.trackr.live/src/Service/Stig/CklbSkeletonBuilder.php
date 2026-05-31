<?php

namespace App\Service\Stig;

/**
 * Builds a blank CKLB (every rule Not_Reviewed) from a STIG XCCDF file.
 *
 * The output shape deliberately mirrors what public/js/ckl_viewer.js
 * parseCklb()/normalizeRule() consume, so a skeleton loads directly in the
 * CKL/CKLB viewer — and it carries the standard top-level CKLB keys
 * (cklb_version, target_data, stigs…) so the raw file also opens in DISA
 * STIG Viewer 3.x. This is the server-side half of the STIG wizard
 * (GitHub issue #15): the skeleton is built from PUBLIC STIG content only;
 * the user's wizard answers are applied client-side and never reach here.
 */
class CklbSkeletonBuilder
{
    private const XCCDF_NS = 'http://checklists.nist.gov/xccdf/1.1';

    /** VulnDiscussion and its siblings, packed into the XCCDF Rule <description> blob. */
    private const DESC_FIELDS = [
        'VulnDiscussion', 'FalsePositives', 'FalseNegatives', 'Documentable',
        'Mitigations', 'SeverityOverrideGuidance', 'PotentialImpacts',
        'ThirdPartyTools', 'MitigationControl', 'Responsibility', 'IAControls',
    ];

    /**
     * @param array $meta Optional toc context: ['version'=>'6','release'=>'4'].
     */
    public function build(string $xccdfPath, array $meta = []): array
    {
        $xml = @simplexml_load_file($xccdfPath);
        if ($xml === false) {
            throw new \RuntimeException("Unable to parse XCCDF: $xccdfPath");
        }
        $xml->registerXPathNamespace('x', self::XCCDF_NS);

        $benchTitle = trim((string) $xml->title);
        $benchId    = (string) $xml->attributes()['id'];
        $stigUuid   = $this->uuid();

        $rules = [];
        foreach ($xml->xpath('//x:Group') ?: [] as $group) {
            $rules[] = $this->buildRule($group, $stigUuid);
        }

        return [
            'title'                 => $benchTitle,
            'id'                    => $this->uuid(),
            'cklb_version'          => '1.0',
            'stig_guid'             => '',
            'classification'        => 'UNCLASSIFIED',
            'customname'            => '',
            'evaluate_stig_version' => '',
            'active'                => true,
            'mode'                  => 1,
            'has_path'              => false,
            'cci_data'              => (object) [],
            'target_data'           => $this->emptyTargetData(),
            'stigs'                 => [[
                'evaluate_stig_'       => false,
                'stig_name'            => $benchTitle,
                'display_name'         => $benchTitle,
                'stig_id'              => $benchId,
                'release_info'         => $this->releaseInfo($xml, $meta),
                'version'              => (string) ($meta['version'] ?? ''),
                'uuid'                 => $stigUuid,
                'reference_identifier' => $benchId,
                'size'                 => count($rules),
                'rules'                => $rules,
            ]],
        ];
    }

    private function buildRule(\SimpleXMLElement $group, string $stigUuid): array
    {
        $groupId    = (string) $group->attributes()['id'];
        $groupTitle = trim((string) $group->title);
        $rule       = $group->Rule;

        $desc = $this->parseDescription((string) $rule->description);

        $ccis = [];
        $legacy = [];
        foreach ($rule->ident as $ident) {
            $system = strtolower((string) $ident->attributes()['system']);
            $value  = trim((string) $ident);
            if ($value === '') {
                continue;
            }
            if (str_contains($system, 'cci')) {
                $ccis[] = $value;
            } elseif (str_contains($system, 'legacy')) {
                $legacy[] = $value;
            }
        }

        $check   = $rule->check;
        $ccRef   = $check->{'check-content-ref'};

        return [
            'uuid'                       => $this->uuid(),
            'stig_uuid'                  => $stigUuid,
            'target_key'                 => null,
            'stig_ref'                   => null,
            'group_id_src'               => $groupId,
            'group_tree'                 => [[
                'id'          => $groupId,
                'title'       => $groupTitle,
                'description' => '<GroupDescription></GroupDescription>',
            ]],
            'group_id'                   => $groupId,
            'severity'                   => (string) $rule->attributes()['severity'],
            'group_title'                => $groupTitle,
            'rule_id_src'                => (string) $rule->attributes()['id'],
            'rule_id'                    => (string) $rule->attributes()['id'],
            'rule_version'               => (string) $rule->version,
            'rule_title'                 => trim((string) $rule->title),
            'fix_text'                   => trim((string) $rule->fixtext),
            'weight'                     => (string) $rule->attributes()['weight'],
            'classification'             => 'UNCLASSIFIED',
            'ccis'                       => $ccis,
            'legacy_ids'                 => $legacy,
            'discussion'                 => $desc['VulnDiscussion'],
            'check_content'              => trim((string) $check->{'check-content'}),
            'check_content_ref'          => [
                'href' => $ccRef ? (string) $ccRef->attributes()['href'] : '',
                'name' => $ccRef ? (string) $ccRef->attributes()['name'] : '',
            ],
            'false_positives'            => $desc['FalsePositives'],
            'false_negatives'            => $desc['FalseNegatives'],
            'documentable'               => strtolower($desc['Documentable']) === 'true',
            'mitigations'                => $desc['Mitigations'],
            'potential_impacts'          => $desc['PotentialImpacts'],
            'third_party_tools'          => $desc['ThirdPartyTools'],
            'mitigation_control'         => $desc['MitigationControl'],
            'responsibility'             => $desc['Responsibility'],
            'security_override_guidance' => $desc['SeverityOverrideGuidance'],
            'ia_controls'                => $desc['IAControls'],
            // Editable assessor fields — blank skeleton.
            'status'                     => 'Not_Reviewed',
            'finding_details'            => '',
            'comments'                   => '',
            'severity_override'          => '',
            'severity_justification'     => '',
        ];
    }

    /**
     * The XCCDF Rule <description> is an HTML-escaped pseudo-XML blob.
     * SimpleXML already entity-decodes it on cast, so we extract each known
     * field with a non-greedy match. Returns every field (empty string if
     * absent) so callers never hit undefined keys.
     */
    private function parseDescription(string $blob): array
    {
        $out = array_fill_keys(self::DESC_FIELDS, '');
        foreach (self::DESC_FIELDS as $field) {
            if (preg_match('#<' . $field . '>(.*?)</' . $field . '>#s', $blob, $m)) {
                $out[$field] = trim($m[1]);
            }
        }
        return $out;
    }

    private function releaseInfo(\SimpleXMLElement $xml, array $meta): string
    {
        foreach ($xml->xpath('//x:plain-text[@id="release-info"]') ?: [] as $pt) {
            $text = trim((string) $pt);
            if ($text !== '') {
                return $text;
            }
        }
        if (!empty($meta['version']) || !empty($meta['release'])) {
            return sprintf('Version %s Release %s', $meta['version'] ?? '', $meta['release'] ?? '');
        }
        return '';
    }

    private function emptyTargetData(): object
    {
        return (object) [
            'target_type'     => '',
            'host_name'       => '',
            'host_ip'         => '',
            'host_mac'        => '',
            'host_fqdn'       => '',
            'host_guid'       => '',
            'role'            => '',
            'technology_area' => '',
            'web_or_database' => false,
            'web_db_site'     => '',
            'web_db_instance' => '',
            'is_ia_controls'  => false,
        ];
    }

    /** RFC-4122 v4 UUID without an external dependency. */
    private function uuid(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }
}
