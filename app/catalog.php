<?php
declare(strict_types=1);

function catalog(): array {
    return [
        'nocturne-chrono' => [
            'sku' => 'T-01', 'name' => 'Nocturne Chrono', 'price' => 52000, 'bracelet' => 'Cuir brun', 'finish' => 'Noir & or', 'size' => '46 mm',
            'image' => 'products/nocturne-chrono.jpg', 'description' => 'Un cadran noir profond, des détails dorés et un bracelet cuir qui donne immédiatement de la tenue.',
            'story' => 'Le détail qui pose la silhouette.', 'movement' => 'Quartz', 'waterproof' => '30 m',
            'gallery' => ['products/nocturne-chrono.jpg','products/nocturne-chrono-angle.jpg','products/nocturne-chrono-lifestyle.jpg','products/nocturne-chrono-closeup.jpg','products/nocturne-chrono-wrist.jpg'],
            'variants' => ['Noir & brun' => 'products/nocturne-chrono.jpg', 'Noir intense' => 'products/variants/nocturne-noir-noir.webp', 'Noir & rouge' => 'products/variants/nocturne-noir-rouge.webp'],
            'specs' => ['Diamètre du cadran' => '46 mm', 'Épaisseur' => '13 mm', 'Mouvement' => 'Quartz', 'Fonctions' => 'Chronographe, calendrier et trois aiguilles', 'Étanchéité annoncée' => '30 m', 'Verre' => 'Verre minéral renforcé', 'Bracelet' => 'Cuir synthétique brun', 'Boucle' => 'Ardillon, acier inoxydable'],
            'features' => [['Un cadran qui se lit d’un regard', 'Repères dorés, compteurs contrastés et grande ouverture circulaire pour une présence immédiate au poignet.'], ['Faite pour suivre le rythme', 'Chronographe, calendrier et trois aiguilles réunissent les fonctions utiles dans une silhouette affirmée.'], ['Une construction qui dure', 'Verre minéral renforcé et étanchéité annoncée à 30 m pour les usages du quotidien.']],
        ],
        'azur-squelette' => [
            'sku' => 'T-02', 'name' => 'Azur Squelette', 'price' => 62000, 'bracelet' => 'Acier bleu', 'finish' => 'Acier brossé', 'size' => '46 mm',
            'image' => 'products/azur-squelette.jpg', 'description' => 'Un bleu franc, un boîtier à facettes et un cadran ouvert qui laisse apparaître la mécanique.',
            'story' => 'Une mécanique qui se remarque.', 'movement' => 'Mécanique visible', 'waterproof' => '30 m',
            'gallery' => ['products/azur-squelette.jpg','products/azur-squelette-angle.jpg','products/azur-squelette-lifestyle.jpg','products/azur-squelette-portrait.jpg','products/azur-squelette-bureau.jpg'],
            'variants' => ['Bleu & or' => 'products/azur-squelette-lifestyle.jpg', 'Noir & or' => 'products/variants/azur-noir-or.webp'],
            'specs' => ['Diamètre du cadran' => '46 mm', 'Épaisseur' => '11 mm', 'Mouvement' => 'Mécanique', 'Boîtier' => 'Octogonal à facettes', 'Cadran' => 'Squelette bleu', 'Fond' => 'Transparent', 'Étanchéité annoncée' => '30 m', 'Fermoir' => 'Boucle déployante, acier inoxydable'],
            'features' => [['Le mouvement à ciel ouvert', 'Le cadran squelette laisse apparaître les rouages et le balancier.'], ['Un boîtier qui accroche la lumière', 'Les facettes octogonales, le bleu intense et les touches métalliques apportent du relief.'], ['Pensée sous tous les angles', 'Fond transparent, couronne vissée et boucle déployante complètent la construction.']],
        ],
        'eclipse-lunaire' => [
            'sku' => 'T-03', 'name' => 'Éclipse Lunaire', 'price' => 59000, 'bracelet' => 'Cuir brun', 'finish' => 'Acier poli', 'size' => '42,8 mm',
            'image' => 'products/eclipse-lunaire.jpg', 'description' => 'Un cadran argenté, une ouverture mécanique et une phase de lune qui captent la lumière avec retenue.',
            'story' => 'Une allure habillée, éclairée par la lune.', 'movement' => 'Mécanique', 'waterproof' => '20 m',
            'gallery' => ['products/eclipse-lunaire.jpg','products/eclipse-lunaire-angle.jpg','products/eclipse-lunaire-lifestyle.jpg','products/eclipse-lunaire-closeup.jpg','products/eclipse-lunaire-bureau.jpg'],
            'variants' => ['Argent & brun' => 'products/eclipse-lunaire.jpg'],
            'specs' => ['Diamètre du cadran' => '42,8 mm', 'Épaisseur' => '14 mm', 'Mouvement' => 'Mécanique', 'Cadran' => 'Argenté à phase de lune', 'Indications' => 'Phase de lune, jour, date et mois', 'Luminescence' => 'Repères lumineux', 'Étanchéité annoncée' => '20 m', 'Bracelet' => 'Cuir véritable brun'],
            'features' => [['La lune au centre du regard', 'La phase de lune anime le haut du cadran et donne au modèle sa profondeur singulière.'], ['Une mécanique à contempler', 'L’ouverture à 8 heures et le fond transparent laissent voir le mouvement.'], ['Une montre complète', 'Jour, date, mois, repères lumineux et bracelet cuir véritable, sans alourdir la ligne.']],
        ],
    ];
}
function product_by_slug(string $slug): ?array { $all = catalog(); return $all[$slug] ?? null; }
