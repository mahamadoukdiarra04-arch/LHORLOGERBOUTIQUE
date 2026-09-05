<?php
declare(strict_types=1);

function catalog(): array {
    $products = [
        'nocturne-chrono' => [
            'sku' => 'T-01', 'name' => 'Nocturne Chrono', 'price' => 52000, 'bracelet' => 'Cuir brun', 'finish' => 'Noir & or', 'size' => '46 mm',
            'image' => 'products/nocturne-chrono.jpg', 'description' => 'Un cadran noir profond, des détails dorés et un bracelet cuir qui donne immédiatement de la tenue.',
            'story' => 'Le détail qui pose la silhouette.', 'movement' => 'Quartz', 'waterproof' => '30 m',
            'gallery' => ['products/nocturne-chrono.jpg','products/nocturne-chrono-angle.jpg','products/nocturne-chrono-lifestyle.jpg','products/nocturne-chrono-closeup.jpg','products/nocturne-chrono-wrist.jpg','products/variants/nocturne-noir-rouge-angle.jpg','products/variants/nocturne-noir-noir-angle.jpg','products/variants/nocturne-argent-noir-angle.jpg','products/variants/nocturne-noir-brun-angle.jpg','products/variants/nocturne-noir-camel-angle.jpg'],
            'variants' => ['Noir & brun' => 'products/variants/nocturne-noir-brun.jpg', 'Noir intense' => 'products/variants/nocturne-noir-noir.jpg', 'Noir & rouge' => 'products/variants/nocturne-noir-rouge.jpg', 'Bleu & brun' => 'products/variants/nocturne-bleu-brun.jpg', 'Argent & noir' => 'products/variants/nocturne-argent-noir.jpg', 'Noir & camel' => 'products/variants/nocturne-noir-camel.jpg'],
            'specs' => ['Diamètre du cadran' => '46 mm', 'Épaisseur' => '13 mm', 'Mouvement' => 'Quartz', 'Fonctions' => 'Chronographe, calendrier et trois aiguilles', 'Étanchéité annoncée' => '30 m', 'Verre' => 'Verre minéral renforcé', 'Bracelet' => 'Cuir synthétique brun', 'Boucle' => 'Ardillon, acier inoxydable'],
            'features' => [['Un cadran qui se lit d’un regard', 'Repères dorés, compteurs contrastés et grande ouverture circulaire pour une présence immédiate au poignet.'], ['Faite pour suivre le rythme', 'Chronographe, calendrier et trois aiguilles réunissent les fonctions utiles dans une silhouette affirmée.'], ['Une construction qui dure', 'Verre minéral renforcé et étanchéité annoncée à 30 m pour les usages du quotidien.']],
        ],
        'azur-squelette' => [
            'sku' => 'T-02', 'name' => 'Azur Squelette', 'price' => 62000, 'bracelet' => 'Acier bleu', 'finish' => 'Acier brossé', 'size' => '46 mm',
            'image' => 'products/azur-squelette.jpg', 'description' => 'Un bleu franc, un boîtier à facettes et un cadran ouvert qui laisse apparaître la mécanique.',
            'story' => 'Une mécanique qui se remarque.', 'movement' => 'Mécanique visible', 'waterproof' => '30 m',
            'gallery' => ['products/azur-squelette.jpg','products/azur-squelette-angle.jpg','products/azur-squelette-lifestyle.jpg','products/azur-squelette-portrait.jpg','products/azur-squelette-bureau.jpg'],
            'variants' => ['Bleu signature' => 'products/azur-squelette-lifestyle.jpg', 'Or squelette' => 'products/variants/azur-or-squelette.jpg', 'Noir squelette' => 'products/variants/azur-noir-squelette.jpg'],
            'specs' => ['Diamètre du cadran' => '46 mm', 'Épaisseur' => '11 mm', 'Mouvement' => 'Mécanique', 'Boîtier' => 'Octogonal à facettes', 'Cadran' => 'Squelette bleu', 'Fond' => 'Transparent', 'Étanchéité annoncée' => '30 m', 'Fermoir' => 'Boucle déployante, acier inoxydable'],
            'features' => [['Le mouvement à ciel ouvert', 'Le cadran squelette laisse apparaître les rouages et le balancier.'], ['Un boîtier qui accroche la lumière', 'Les facettes octogonales, le bleu intense et les touches métalliques apportent du relief.'], ['Pensée sous tous les angles', 'Fond transparent, couronne vissée et boucle déployante complètent la construction.']],
        ],
        'eclipse-lunaire' => [
            'sku' => 'T-03', 'name' => 'Éclipse Lunaire', 'price' => 59000, 'bracelet' => 'Cuir brun', 'finish' => 'Acier poli', 'size' => '42,8 mm',
            'image' => 'products/eclipse-lunaire.jpg', 'description' => 'Un cadran argenté, une ouverture mécanique et une phase de lune qui captent la lumière avec retenue.',
            'story' => 'Une allure habillée, éclairée par la lune.', 'movement' => 'Mécanique', 'waterproof' => '20 m',
            'gallery' => ['products/eclipse-lunaire.jpg','products/eclipse-lunaire-angle.jpg','products/eclipse-lunaire-lifestyle.jpg','products/eclipse-lunaire-closeup.jpg','products/eclipse-lunaire-bureau.jpg'],
            'variants' => ['Argent & brun' => 'products/variants/eclipse-argent-brun.jpg', 'Noir & or' => 'products/variants/eclipse-noir-or.jpg', 'Argent & champagne' => 'products/variants/eclipse-argent-champagne.jpg', 'Argent & noir' => 'products/variants/eclipse-argent-noir.jpg', 'Argent & or' => 'products/variants/eclipse-argent-or.jpg', 'Noir intense' => 'products/variants/eclipse-noir-noir.jpg', 'Or & brun' => 'products/variants/eclipse-or-brun.jpg', 'Bleu & or' => 'products/variants/eclipse-bleu-or.jpg'],
            'specs' => ['Diamètre du cadran' => '42,8 mm', 'Épaisseur' => '14 mm', 'Mouvement' => 'Mécanique', 'Cadran' => 'Argenté à phase de lune', 'Indications' => 'Phase de lune, jour, date et mois', 'Luminescence' => 'Repères lumineux', 'Étanchéité annoncée' => '20 m', 'Bracelet' => 'Cuir véritable brun'],
            'features' => [['La lune au centre du regard', 'La phase de lune anime le haut du cadran et donne au modèle sa profondeur singulière.'], ['Une mécanique à contempler', 'L’ouverture à 8 heures et le fond transparent laissent voir le mouvement.'], ['Une montre complète', 'Jour, date, mois, repères lumineux et bracelet cuir véritable, sans alourdir la ligne.']],
        ],
    ];

    // The content of the product pages remains curated here, while the selling
    // price is controlled from the administration panel.
    if (function_exists('db')) {
        try {
            $prices = db()->query('SELECT slug, price_fcfa FROM products')->fetchAll(PDO::FETCH_KEY_PAIR);
            foreach ($prices as $slug => $price) {
                if (isset($products[$slug]) && (int) $price > 0) {
                    $products[$slug]['price'] = (int) $price;
                }
            }
        } catch (Throwable) {
            // Keep the published catalogue available if the database is temporarily unavailable.
        }
    }

    return $products;
}
function product_by_slug(string $slug): ?array { $all = catalog(); return $all[$slug] ?? null; }

/**
 * Return the photo that represents the color actually selected on an order.
 *
 * Older orders may contain harmless differences in spacing or punctuation, so
 * the normalized lookup prevents them from falling back to the generic model
 * photo when the corresponding variant still exists in the catalogue.
 */
function catalog_variant_image(array $catalog, string $slug, ?string $variant): string {
    $product = $catalog[$slug] ?? null;
    $fallback = is_array($product) && !empty($product['image'])
        ? (string) $product['image']
        : 'products/nocturne-chrono.jpg';
    if (!is_array($product)) return $fallback;

    $variant = trim((string) $variant);
    $variants = (array) ($product['variants'] ?? []);
    if ($variant !== '' && isset($variants[$variant]) && trim((string) $variants[$variant]) !== '') {
        return (string) $variants[$variant];
    }

    $normalize = static function (string $value): string {
        $value = mb_strtolower(trim($value), 'UTF-8');
        $value = str_replace([' et ', ' / ', ' - '], ' ', $value);
        return preg_replace('/[^\p{L}\p{N}]+/u', '', $value) ?? '';
    };
    $needle = $normalize($variant);
    if ($needle !== '') {
        foreach ($variants as $name => $image) {
            if ($normalize((string) $name) === $needle && trim((string) $image) !== '') {
                return (string) $image;
            }
        }
    }

    return $fallback;
}
