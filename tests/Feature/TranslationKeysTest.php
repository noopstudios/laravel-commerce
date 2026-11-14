<?php

use Symfony\Component\Finder\Finder;

/**
 * The literal keys passed to __() in a PHP source, unescaped.
 *
 * @return list<string>
 */
function translationKeysIn(string $source): array
{
    preg_match_all('/\b__\(\s*(?:\'((?:[^\'\\\\]|\\\\.)*)\'|"((?:[^"\\\\]|\\\\.)*)")/', $source, $matches, PREG_SET_ORDER);

    return array_map(fn (array $match) => ($match[2] ?? '') !== ''
        ? stripcslashes($match[2])
        : str_replace(['\\\'', '\\\\'], ['\'', '\\'], $match[1] ?? ''), $matches);
}

it('has a pt translation for every key used in the package', function () {
    $translations = json_decode((string) file_get_contents(__DIR__.'/../../resources/lang/pt.json'), true, flags: JSON_THROW_ON_ERROR);
    $missing = [];

    foreach (Finder::create()->files()->in(__DIR__.'/../../src')->name('*.php') as $file) {
        foreach (translationKeysIn($file->getContents()) as $key) {
            if (! array_key_exists($key, $translations)) {
                $missing[] = $key.' ('.$file->getRelativePathname().')';
            }
        }
    }

    expect($missing)->toBe([]);
});
