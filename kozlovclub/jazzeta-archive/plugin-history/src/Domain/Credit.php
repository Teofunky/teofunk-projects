<?php

namespace Kozlov\Gazeta\Domain;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Photo credits and picture sources (0.5.6).
 *
 * Drafts list the picture's page (Wikimedia Commons, Flickr…) and its licence among the sources
 * of the news, and add service notes to the credit («Кадрирование для полосы. Источник и
 * лицензия — в ссылках к материалу»). Readers get the news sources under the article and the
 * picture's credit under the picture: author, platform and licence as links, no service notes.
 * The JSON 1.1 data is not changed; this is presentation. Pure functions, no WordPress.
 */
final class Credit
{
    /** Hosts whose pages describe a picture or a licence, not the news itself. */
    private const IMAGE_HOSTS = [
        'commons.wikimedia.org', 'upload.wikimedia.org', 'creativecommons.org',
        'flickr.com', 'unsplash.com', 'pexels.com', 'pixabay.com',
    ];

    /**
     * Sentences of a credit that only explain the editor's work to the editor. 0.5.7: «кадрир»
     * catches every form («кадрируется», «кадрирование»), plus «Снимок уменьшен», «в вёрстке».
     */
    private const SERVICE_NOTE = '/кадрир|кадр изменён|кадр изменен|снимок уменьшен|в в[её]рстке|в ссылках к материал|источник и лицензи|лицензия и источник/iu';

    /**
     * A sentence with the credit itself (author, platform, licence) is never dropped, even when it
     * also says «кадрировано»: «Фото: Rama / Wikimedia Commons (CC BY 2.0, кадрировано).»
     */
    private const CREDIT_CORE = '/фото|©|\bCC(?:0|\b)|wikimedia|wikipedia|flickr|unsplash|pexels|pixabay|автор/iu';

    /** A service note that says the picture was changed: the reader still gets one word about it. */
    private const CHANGE_NOTE = '/кадрир|кадр изменён|кадр изменен|обрезан/iu';

    /** Dashes a typograph or an editor may put between the parts of a licence name: «CC BY–SA 2.0». */
    private const DASH = '[ \x{2010}\x{2011}\x{2012}\x{2013}\x{2014}\-]';

    /** A bare address in the credit text, with the dash before it: « — https://creativecommons.org/…». */
    private const BARE_URL = '/\s*[\x{2013}\x{2014}\-]?\s*\bhttps?:\/\/[^\s)]+?(?=[.,;:!?]*(?:[\s)]|$))/u';

    public static function isImageSource(array $source): bool
    {
        $url = is_scalar($source['url'] ?? null) ? (string) $source['url'] : '';
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $host = (string) preg_replace('/^(www|m)\./', '', $host);
        if ($host === '') {
            return false;
        }
        if (in_array($host, self::IMAGE_HOSTS, true)) {
            return true;
        }
        // A file page of Wikipedia («/wiki/File:…», «/wiki/Файл:…») is a picture, an article is not.
        if (substr($host, -strlen('.wikipedia.org')) === '.wikipedia.org') {
            $path = rawurldecode((string) parse_url($url, PHP_URL_PATH));
            return (bool) preg_match('~^/wiki/(File|Файл|Image|Изображение):~u', $path);
        }
        return false;
    }

    /**
     * Splits sources into those of the news and those of the picture, keeping the order.
     * @return array{0:array,1:array} [news, picture]
     */
    public static function split(array $sources): array
    {
        $news = [];
        $picture = [];
        foreach ($sources as $source) {
            if (is_array($source) && self::isImageSource($source)) {
                $picture[] = $source;
            } else {
                $news[] = $source;
            }
        }
        return [$news, $picture];
    }

    /**
     * The credit without service notes and bare addresses; the rest of the text is left as written.
     * 0.5.7: when a dropped note said the picture was cropped, «Кадрировано.» is added instead:
     * Creative Commons licences ask to mark changes, the reader does not need the editor's wording.
     */
    public static function clean(string $credit): string
    {
        $credit = trim((string) preg_replace('/\s+/u', ' ', $credit));
        if ($credit === '') {
            return $credit;
        }
        // The address of the licence or of the picture is a link, never text (see segments()).
        if (preg_match(self::BARE_URL, $credit)) {
            $credit = (string) preg_replace(self::BARE_URL, '', $credit);
            $credit = (string) preg_replace('/\s+([.,;:!?)])/u', '$1', $credit);   // «2.0 .» → «2.0.»
            $credit = (string) preg_replace('/[,;:]+(?=[.,;:!?)]|$)/u', '', $credit); // «2.0,.» → «2.0.»
            $credit = trim((string) preg_replace('/\(\s*\)/u', '', $credit));
            if ($credit !== '' && !preg_match('/[.!?…)»]$/u', $credit)) {
                $credit .= '.';
            }
        }
        if (!preg_match(self::SERVICE_NOTE, $credit)) {
            return $credit;
        }
        $sentences = preg_split('/(?<=[.!?…])\s+(?=\S)/u', $credit) ?: [$credit];
        $changed = false;
        $kept = array_filter($sentences, static function ($sentence) use (&$changed) {
            if (!preg_match(self::SERVICE_NOTE, $sentence) || preg_match(self::CREDIT_CORE, $sentence)) {
                return true;
            }
            if (preg_match(self::CHANGE_NOTE, $sentence)) {
                $changed = true;
            }
            return false;
        });
        $out = trim(implode(' ', $kept));
        if ($changed && $out !== '') {
            $out .= (preg_match('/[.!?…]$/u', $out) ? '' : '.') . ' Кадрировано.';
        }
        return $out;
    }

    /** The first creativecommons.org address written in the credit text itself, or ''. */
    public static function licenceUrlInText(string $credit): string
    {
        if (preg_match('~https?://(?:www\.)?creativecommons\.org/[^\s]*?(?=[.,;:)]*(?:\s|$))~u', $credit, $m)) {
            return $m[0];
        }
        return '';
    }

    /**
     * Deed address of a Creative Commons licence named in the text: «CC BY 2.0», «CC BY-SA 4.0»,
     * «CC0 1.0». Unknown or incomplete names give ''.
     */
    public static function licenceUrl(string $name): string
    {
        $name = strtoupper(trim((string) preg_replace('/' . self::DASH . '+/u', '-', trim($name))));
        $name = str_replace('-', ' ', $name);
        if (preg_match('/^CC0(?:[ -]1\.0)?$/', $name)) {
            return 'https://creativecommons.org/publicdomain/zero/1.0/';
        }
        if (!preg_match('/^CC[ -]((?:BY|SA|NC|ND)(?:[ -](?:SA|NC|ND))*)[ -](\d\.\d)$/', $name, $m)) {
            return '';
        }
        $parts = preg_split('/[ -]/', $m[1]) ?: [];
        if (($parts[0] ?? '') !== 'BY' || !in_array($m[2], ['1.0', '2.0', '2.5', '3.0', '4.0'], true)) {
            return '';
        }
        return 'https://creativecommons.org/licenses/' . strtolower(implode('-', $parts)) . '/' . $m[2] . '/';
    }

    /**
     * The credit as segments for the template: plain text and links. The licence name links to
     * its deed (the licence source when one is given, else the canonical address); the name of
     * the picture's platform links to the picture's page.
     * @param array $pictureSources sources for which isImageSource() is true
     * @return array<int,array{text:string,url:string}> url '' for plain text
     */
    public static function segments(string $credit, array $pictureSources): array
    {
        $written = self::licenceUrlInText($credit);
        $credit = self::clean($credit);
        if ($credit === '') {
            return [];
        }
        $links = [];
        // Licence. 0.5.7: any dash between the parts («CC BY–SA 2.0»); the address written in the
        // credit itself («…/by-sa/2.0/fr/») wins over the canonical one.
        $d = self::DASH;
        if (preg_match('/\bCC(?:0(?:' . $d . '1\.0)?|' . $d . '(?:BY|SA|NC|ND)(?:' . $d . '(?:SA|NC|ND))*' . $d . '\d\.\d)(?![\p{L}\p{N}])/u', $credit, $m, PREG_OFFSET_CAPTURE)) {
            $url = '';
            foreach ($pictureSources as $source) {
                if (strpos((string) ($source['url'] ?? ''), 'creativecommons.org/') !== false) {
                    $url = (string) $source['url'];
                    break;
                }
            }
            $url = $url !== '' ? $url : ($written !== '' ? $written : self::licenceUrl($m[0][0]));
            if ($url !== '') {
                $links[] = [$m[0][1], strlen($m[0][0]), $url];
            }
        }
        // The picture's page, by the platform's name as the credit spells it.
        foreach ($pictureSources as $source) {
            $url = (string) ($source['url'] ?? '');
            if ($url === '' || strpos($url, 'creativecommons.org/') !== false) {
                continue;
            }
            $names = array_filter([(string) ($source['publisher'] ?? ''), self::platformName($url)]);
            foreach ($names as $name) {
                $at = strpos($credit, $name);
                if ($at !== false) {
                    $links[] = [$at, strlen($name), $url];
                    break 2;
                }
            }
        }
        usort($links, static function ($a, $b) {
            return $a[0] <=> $b[0];
        });
        $out = [];
        $cursor = 0;
        foreach ($links as [$at, $length, $url]) {
            if ($at < $cursor) {
                continue; // overlapping matches: the first one wins
            }
            if ($at > $cursor) {
                $out[] = ['text' => substr($credit, $cursor, $at - $cursor), 'url' => ''];
            }
            $out[] = ['text' => substr($credit, $at, $length), 'url' => $url];
            $cursor = $at + $length;
        }
        if ($cursor < strlen($credit)) {
            $out[] = ['text' => substr($credit, $cursor), 'url' => ''];
        }
        return $out;
    }

    private static function platformName(string $url): string
    {
        $host = (string) preg_replace('/^(www|m)\./', '', strtolower((string) parse_url($url, PHP_URL_HOST)));
        $names = ['commons.wikimedia.org' => 'Wikimedia Commons', 'upload.wikimedia.org' => 'Wikimedia Commons', 'flickr.com' => 'Flickr', 'unsplash.com' => 'Unsplash', 'pexels.com' => 'Pexels', 'pixabay.com' => 'Pixabay'];
        if (isset($names[$host])) {
            return $names[$host];
        }
        return substr($host, -strlen('.wikipedia.org')) === '.wikipedia.org' ? 'Wikipedia' : '';
    }
}
