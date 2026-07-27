<?php
/**
 * SEO helpers — URLs absolutas, meta tags, Schema.org, Analytics.
 */
require_once __DIR__ . '/pwa-url.php';

if (!function_exists('tmz_site_url')) {
    function tmz_site_url(string $path = ''): string
    {
        $base = rtrim(tmz_pwa_base_url(), '/');
        $path = '/' . ltrim(str_replace('\\', '/', $path), '/');
        if ($path === '/') {
            return $base . '/';
        }
        return $base . $path;
    }
}

if (!function_exists('tmz_seo_defaults')) {
    function tmz_seo_defaults(): array
    {
        return [
            'site_name' => 'TrackMoz',
            'title' => 'TrackMoz | Plataforma de Gestão de Fretes em Moçambique',
            'description' => 'A plataforma inteligente que conecta empresas, transportadoras e camionistas em Moçambique. Gestão de fretes, contratos digitais, rastreamento GPS e logística.',
            'keywords' => 'fretes Moçambique, transporte rodoviário, cargas Moçambique, transportadora, camionistas, logística, gestão de fretes, TrackMoz, rastreamento GPS',
            'image' => tmz_site_url('assets/img/Logo_com_background.png'),
            'type' => 'website',
            'robots' => 'index,follow',
            'canonical' => null,
            'locale' => 'pt_MZ',
        ];
    }
}

if (!function_exists('tmz_seo_ga_id')) {
    /** ID GA4 (ex.: G-XXXXXXXX). Vazio = desactivado. */
    function tmz_seo_ga_id(): string
    {
        if (defined('TMZ_GA_MEASUREMENT_ID')) {
            return trim((string)TMZ_GA_MEASUREMENT_ID);
        }
        $local = __DIR__ . '/../config/seo.local.php';
        if (is_file($local)) {
            $cfg = include $local;
            if (is_array($cfg) && !empty($cfg['ga_measurement_id'])) {
                return trim((string)$cfg['ga_measurement_id']);
            }
        }
        $env = getenv('TMZ_GA_MEASUREMENT_ID');
        return is_string($env) ? trim($env) : '';
    }
}

if (!function_exists('tmz_seo_render_head')) {
    /**
     * Imprime title, description, OG, Twitter, canonical, favicons e JSON-LD.
     * @param array $overrides chaves de tmz_seo_defaults()
     * @param bool $withSchema incluir Schema Organization/WebSite/SoftwareApplication
     */
    function tmz_seo_render_head(array $overrides = [], bool $withSchema = true): void
    {
        $d = array_merge(tmz_seo_defaults(), $overrides);
        $canonical = $d['canonical'] ?: tmz_site_url($_SERVER['REQUEST_URI'] ?? '/');
        // Limpar query strings sensíveis do canonical
        $parts = parse_url($canonical);
        $canonPath = ($parts['path'] ?? '/');
        $canonical = tmz_site_url($canonPath === '' ? '/' : $canonPath);
        if (!empty($overrides['canonical'])) {
            $canonical = $overrides['canonical'];
        }

        $title = (string)$d['title'];
        $desc = (string)$d['description'];
        $image = (string)$d['image'];
        $site = (string)$d['site_name'];
        $type = (string)$d['type'];
        $keywords = (string)$d['keywords'];
        $robots = (string)$d['robots'];
        $locale = (string)$d['locale'];

        $esc = static function ($v): string {
            return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        };

        $iconBase = rtrim((string)BASE_URL, '/') . '/assets/img/icons';
        // Fallback se icons/ não existir no path esperado
        $iconAlt = rtrim((string)BASE_URL, '/') . '/assets/img';

        echo '<title>' . $esc($title) . "</title>\n";
        echo '<meta name="description" content="' . $esc($desc) . "\">\n";
        echo '<meta name="keywords" content="' . $esc($keywords) . "\">\n";
        echo '<meta name="robots" content="' . $esc($robots) . "\">\n";
        echo '<meta name="author" content="TrackMoz">' . "\n";
        echo '<link rel="canonical" href="' . $esc($canonical) . '">' . "\n";

        // Open Graph
        echo '<meta property="og:type" content="' . $esc($type) . '">' . "\n";
        echo '<meta property="og:site_name" content="' . $esc($site) . '">' . "\n";
        echo '<meta property="og:title" content="' . $esc($title) . '">' . "\n";
        echo '<meta property="og:description" content="' . $esc($desc) . '">' . "\n";
        echo '<meta property="og:url" content="' . $esc($canonical) . '">' . "\n";
        echo '<meta property="og:image" content="' . $esc($image) . '">' . "\n";
        echo '<meta property="og:locale" content="' . $esc($locale) . '">' . "\n";

        // Twitter
        echo '<meta name="twitter:card" content="summary_large_image">' . "\n";
        echo '<meta name="twitter:title" content="' . $esc($title) . '">' . "\n";
        echo '<meta name="twitter:description" content="' . $esc($desc) . '">' . "\n";
        echo '<meta name="twitter:image" content="' . $esc($image) . '">' . "\n";

        // Favicons (além do pwa-head)
        echo '<link rel="icon" href="' . $esc($iconBase) . '/icon-192.png" sizes="192x192" type="image/png">' . "\n";
        echo '<link rel="shortcut icon" href="' . $esc($iconAlt) . '/Logo_sem_background.png">' . "\n";
        echo '<link rel="apple-touch-icon" href="' . $esc($iconBase) . '/apple-touch-icon.png">' . "\n";

        if ($withSchema) {
            $schemas = [
                [
                    '@context' => 'https://schema.org',
                    '@type' => 'Organization',
                    'name' => 'TrackMoz',
                    'url' => tmz_site_url('/'),
                    'logo' => tmz_site_url('assets/img/Logo_com_background.png'),
                    'email' => 'contacto@trackmoz.mz',
                    'address' => [
                        '@type' => 'PostalAddress',
                        'addressLocality' => 'Maputo',
                        'addressCountry' => 'MZ',
                    ],
                    'sameAs' => [],
                ],
                [
                    '@context' => 'https://schema.org',
                    '@type' => 'WebSite',
                    'name' => 'TrackMoz',
                    'url' => tmz_site_url('/'),
                    'description' => $desc,
                    'inLanguage' => 'pt-MZ',
                ],
                [
                    '@context' => 'https://schema.org',
                    '@type' => 'SoftwareApplication',
                    'name' => 'TrackMoz',
                    'applicationCategory' => 'BusinessApplication',
                    'operatingSystem' => 'Web',
                    'offers' => [
                        '@type' => 'Offer',
                        'price' => '0',
                        'priceCurrency' => 'MZN',
                    ],
                    'description' => $desc,
                    'url' => tmz_site_url('/'),
                    'image' => $image,
                    'inLanguage' => 'pt-MZ',
                    'featureList' => [
                        'Gestão de fretes',
                        'Contratos digitais',
                        'Rastreamento GPS',
                        'Confirmação OTP de entrega',
                        'Parcerias empresa-transportadora',
                    ],
                ],
            ];
            echo '<script type="application/ld+json">' .
                json_encode($schemas, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) .
                "</script>\n";
        }

        $ga = tmz_seo_ga_id();
        if ($ga !== '' && preg_match('/^G-[A-Z0-9]+$/i', $ga)) {
            echo '<!-- Google Analytics -->' . "\n";
            echo '<script async src="https://www.googletagmanager.com/gtag/js?id=' . $esc($ga) . '"></script>' . "\n";
            echo "<script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}gtag('js',new Date());gtag('config'," . json_encode($ga) . ");</script>\n";
        }
    }
}
