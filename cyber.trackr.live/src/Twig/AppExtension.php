<?php
namespace App\Twig;

use Twig\Attribute\AsTwigFilter;
class AppExtension
{
    #[AsTwigFilter(name: 'regex_replace')]
    public function regex_replace($string, $pattern = '', $replacement = '')
    {
        return preg_replace($pattern, (string) $replacement, (string) $string);
    }

    #[AsTwigFilter(name: 'sha1')]
    public function sha1(string $value): string
    {
        return sha1($value);
    }
}
