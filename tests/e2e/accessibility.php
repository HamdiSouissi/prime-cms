<?php

declare(strict_types=1);

/**
 * Vérification d'accessibilite basique (WCAG 2.1 niveau A) sur les pages publiques.
 *
 * Utilisation : php tests/e2e/accessibility.php [base-url]
 *   base-url par défaut : http://localhost:8080
 *
 * Retourne 0 si tous les critères sont respects, 1 sinon.
 */

$baseUrl = $argv[1] ?? 'http://localhost:8080';
$pages = ['/', '/install', '/admin/login'];

$failures = 0;

foreach ($pages as $path) {
    $url = rtrim($baseUrl, '/') . $path;
    $html = @file_get_contents($url);

    if ($html === false) {
        echo "::error::Impossible de fetch $url\n";
        $failures++;
        continue;
    }

    echo "Verification de l'accessibilite : $url (" . strlen($html) . " octets)\n";

    // 1. Langue de la page declaree
    if (!preg_match('/<html[^>]+lang=["\'][^"\']+["\']/i', $html)) {
        echo "::error:: $url : attribut lang manquant sur <html>\n";
        $failures++;
    }

    // 2. Titre de page present
    if (!preg_match('/<title[^>]*>.*<\/title>/is', $html)) {
        echo "::error:: $url : <title> manquant\n";
        $failures++;
    }

    // 3. Au moins un titre de section (h1-h6)
    if (!preg_match('/<h[1-6][^>]*>/i', $html)) {
        echo "::error:: $url : aucun titre de section (h1-h6)\n";
        $failures++;
    }

    // 4. Toutes les images ont un attribut alt
    preg_match_all('/<img[^>]+>/i', $html, $imgs);
    foreach ($imgs[0] as $img) {
        if (!preg_match('/\balt=["\']/i', $img)) {
            $src = preg_match('/\bsrc=["\']([^"\']+)["\']/i', $img, $m) ? $m[1] : 'inconnu';
            echo "::error:: $url : image sans alt : $src\n";
            $failures++;
        }
    }

    // 5. Les inputs ont un label, aria-label ou aria-labelledby
    preg_match_all('/<input[^>]+>/i', $html, $inputs);
    foreach ($inputs[0] as $input) {
        $type = preg_match('/\btype=["\']([^"\']*)["\']/i', $input, $t) ? strtolower($t[1]) : 'text';
        if (in_array($type, ['hidden', 'submit', 'button'], true)) {
            continue;
        }
        if (!preg_match('/\b(aria-label|aria-labelledby)=["\']/i', $input)) {
            $id = preg_match('/\bid=["\']([^"\']+)["\']/i', $input, $m) ? $m[1] : null;
            if ($id === null || !preg_match('/<label[^>]+for=["\']' . preg_quote($id, '/') . '["\']/i', $html)) {
                echo "::error:: $url : input sans label associe ($type)\n";
                $failures++;
            }
        }
    }
}

if ($failures > 0) {
    echo "\n$failures critere(s) d'accessibilite non respecte(s).\n";
    exit(1);
}

echo "\nTous les criteres d'accessibilite sont respects.\n";
exit(0);
