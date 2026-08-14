export type Bracelet = "Cuir" | "Acier" | "Maille";
export type Finish = "Or" | "Acier" | "Noir";
export type Size = "38 mm" | "40 mm" | "42 mm" | "42,8 mm" | "46 mm";
export type WatchTone = "noir" | "ivoire" | "vert" | "sable" | "bleu" | "argent";

export type ProductGalleryImage = {
  src: string;
  alt: string;
  label: string;
  caption: string;
};

export type ProductFeature = {
  title: string;
  copy: string;
};

export type ProductEditorial = {
  image: string;
  alt: string;
  eyebrow: string;
  title: string;
  copy: string;
};

export type ProductVariant = {
  id: string;
  label: string;
  description: string;
  swatch: string;
  image: ProductGalleryImage;
};

export type ProductBenefit = {
  mark: string;
  label: string;
  value: string;
  copy: string;
};

export type WatchProduct = {
  id: string;
  slug: string;
  name: string;
  reference: string;
  image: string;
  gallery: ProductGalleryImage[];
  variants: ProductVariant[];
  shortDescription: string;
  description: string;
  price: number;
  bracelet: Bracelet;
  finish: Finish;
  size: Size;
  dial: string;
  tone: WatchTone;
  badge?: "Nouveau" | "Dernières pièces";
  storyTitle: string;
  styleNote: string;
  highlights: Array<[string, string]>;
  specifications: Array<[string, string]>;
  features: ProductFeature[];
  benefits: ProductBenefit[];
  editorial: ProductEditorial;
};

export const products: WatchProduct[] = [
  {
    id: "tuma-01",
    slug: "nocturne-chrono",
    name: "Nocturne Chrono",
    reference: "T-01",
    image: "/products/nocturne-chrono.png",
    gallery: [
      {
        src: "/products/nocturne-chrono.png",
        alt: "Nocturne Chrono portée au poignet, cadran noir et détails dorés",
        label: "Portée",
        caption: "Nocturne Chrono — vue portée",
      },
      {
        src: "/products/nocturne-chrono-angle.png",
        alt: "Nocturne Chrono vue en angle sur une surface sombre",
        label: "Vue produit",
        caption: "Nocturne Chrono — boîtier et bracelet",
      },
      {
        src: "/products/nocturne-chrono-lifestyle.png",
        alt: "Nocturne Chrono portée dans un bureau aux tons sombres",
        label: "Au quotidien",
        caption: "Nocturne Chrono — allure de tous les jours",
      },
      {
        src: "/products/nocturne-chrono-closeup.png",
        alt: "Gros plan sur Nocturne Chrono portée au poignet",
        label: "Détail",
        caption: "Nocturne Chrono — cadran et bracelet",
      },
      {
        src: "/products/nocturne-chrono-wrist.png",
        alt: "Nocturne Chrono portée sur un fond en cuir brun",
        label: "Gros plan",
        caption: "Nocturne Chrono — fonctions et finitions",
      },
    ],
    variants: [
      {
        id: "noir-brun",
        label: "Noir & brun",
        description: "Cadran noir, accents dorés et cuir brun",
        swatch: "linear-gradient(135deg, #201d1a 0 58%, #70452c 58% 100%)",
        image: {
          src: "/products/nocturne-chrono.png",
          alt: "Nocturne Chrono noire et brune portée au poignet",
          label: "Noir & brun",
          caption: "Nocturne Chrono — cadran noir et bracelet brun",
        },
      },
      {
        id: "noir-intense",
        label: "Noir intense",
        description: "Cadran et bracelet noirs",
        swatch: "#161616",
        image: {
          src: "/products/variants/nocturne-noir-noir.png",
          alt: "Nocturne Chrono noire avec bracelet noir",
          label: "Noir intense",
          caption: "Nocturne Chrono — cadran et bracelet noirs",
        },
      },
      {
        id: "noir-rouge",
        label: "Noir & rouge",
        description: "Cadran noir avec bracelet cuir rouge",
        swatch: "linear-gradient(135deg, #1b1b1b 0 58%, #bd342e 58% 100%)",
        image: {
          src: "/products/variants/nocturne-noir-rouge.png",
          alt: "Nocturne Chrono noire avec bracelet rouge",
          label: "Noir & rouge",
          caption: "Nocturne Chrono — contrastes noirs et rouges",
        },
      },
    ],
    shortDescription: "Cadran noir & or · bracelet cuir",
    description: "Une présence sombre, des détails dorés et un bracelet brun qui donne immédiatement de la tenue. Nocturne Chrono est pensée pour les jours où le détail fait toute la différence.",
    price: 52_000,
    bracelet: "Cuir",
    finish: "Noir",
    size: "46 mm",
    dial: "Noir & or",
    tone: "noir",
    badge: "Nouveau",
    storyTitle: "Le détail qui pose la silhouette.",
    styleNote: "Nocturne Chrono privilégie le contraste : un boîtier sombre, des repères dorés et un cuir brun à la texture marquée.",
    highlights: [
      ["Présence", "Boîtier noir, ligne chronographe"],
      ["Fonctions", "Chronographe et calendrier"],
      ["Bracelet", "Cuir brun à boucle acier"],
    ],
    specifications: [
      ["Référence", "L’Horloger T-01"],
      ["Diamètre du cadran", "46 mm"],
      ["Épaisseur", "13 mm"],
      ["Mouvement", "Quartz"],
      ["Fonctions", "Chronographe, calendrier et trois aiguilles"],
      ["Étanchéité annoncée", "30 m"],
      ["Verre", "Verre minéral renforcé"],
      ["Bracelet", "Cuir synthétique brun"],
      ["Boucle", "Ardillon, acier inoxydable"],
      ["Boîtier", "Alliage, ligne chronographe"],
      ["Finition", "Noir & détails dorés"],
      ["Style", "Sport chic"],
    ],
    features: [
      { title: "Un cadran qui se lit d’un regard", copy: "Repères dorés, compteurs contrastés et grande ouverture circulaire pour une présence immédiate au poignet." },
      { title: "Faite pour suivre le rythme", copy: "Chronographe, calendrier et trois aiguilles réunissent les fonctions utiles dans une silhouette affirmée." },
      { title: "Une construction qui dure", copy: "Verre minéral renforcé, boucle acier et étanchéité annoncée à 30 m pour les usages du quotidien." },
    ],
    benefits: [
      { mark: "30 M", label: "Étanchéité annoncée", value: "30 mètres", copy: "Une protection pensée pour les éclaboussures et le quotidien." },
      { mark: "QZ", label: "Mouvement", value: "Quartz", copy: "Une lecture précise et immédiate, sans complication de réglage." },
      { mark: "CHR", label: "Fonctions", value: "Chronographe + calendrier", copy: "Compteurs visibles, date et trois aiguilles au même endroit." },
      { mark: "MIN", label: "Verre", value: "Minéral renforcé", copy: "Une surface conçue pour accompagner le rythme de tous les jours." },
    ],
    editorial: {
      image: "/products/nocturne-chrono-lifestyle.png",
      alt: "Nocturne Chrono au poignet dans un environnement professionnel",
      eyebrow: "Un repère dans la journée",
      title: "Elle donne de la tenue à l’essentiel.",
      copy: "De la première réunion au dernier rendez-vous, le noir, le brun et l’or composent une signature nette sans surjouer.",
    },
  },
  {
    id: "tuma-02",
    slug: "azur-squelette",
    name: "Azur Squelette",
    reference: "T-02",
    image: "/products/azur-squelette.png",
    gallery: [
      {
        src: "/products/azur-squelette.png",
        alt: "Azur Squelette de face avec cadran ouvert bleu",
        label: "Face",
        caption: "Azur Squelette — cadran ouvert",
      },
      {
        src: "/products/azur-squelette-angle.png",
        alt: "Azur Squelette vue en angle sur une surface métallique",
        label: "Vue produit",
        caption: "Azur Squelette — boîtier et bracelet",
      },
      {
        src: "/products/azur-squelette-lifestyle.png",
        alt: "Azur Squelette portée avec un costume bleu",
        label: "Portée",
        caption: "Azur Squelette — portée avec un costume",
      },
      {
        src: "/products/azur-squelette-portrait.png",
        alt: "Azur Squelette portée avec une veste bleue dans un intérieur habillé",
        label: "Style",
        caption: "Azur Squelette — le bleu comme signature",
      },
      {
        src: "/products/azur-squelette-bureau.png",
        alt: "Azur Squelette portée au bureau avec une tenue bleu nuit",
        label: "Bureau",
        caption: "Azur Squelette — une présence au quotidien",
      },
    ],
    variants: [
      {
        id: "bleu-or",
        label: "Bleu & or",
        description: "Cadran bleu squelette, facettes dorées",
        swatch: "linear-gradient(135deg, #0c4a87 0 58%, #b78333 58% 100%)",
        image: {
          src: "/products/azur-squelette-lifestyle.png",
          alt: "Azur Squelette bleue et dorée portée au poignet",
          label: "Bleu & or",
          caption: "Azur Squelette — bleu profond et accents dorés",
        },
      },
      {
        id: "noir-or",
        label: "Noir & or",
        description: "Boîtier noir et mouvement doré apparent",
        swatch: "linear-gradient(135deg, #191919 0 58%, #b99042 58% 100%)",
        image: {
          src: "/products/variants/azur-noir-or.webp",
          alt: "Azur Squelette noire avec mouvement doré apparent",
          label: "Noir & or",
          caption: "Azur Squelette — mouvement ouvert noir et or",
        },
      },
    ],
    shortDescription: "Cadran squelette bleu · bracelet acier",
    description: "Un bleu franc, un boîtier à facettes et un cadran ouvert qui laisse apparaître la mécanique. Azur Squelette apporte une signature plus audacieuse à une tenue nette.",
    price: 62_000,
    bracelet: "Acier",
    finish: "Acier",
    size: "46 mm",
    dial: "Bleu squelette",
    tone: "bleu",
    badge: "Nouveau",
    storyTitle: "Une mécanique qui se remarque sans se répéter.",
    styleNote: "Azur Squelette réunit un bleu profond, des angles nets et un cadran ouvert. Un choix franc pour les tenues sobres qui acceptent un point de caractère.",
    highlights: [
      ["Mouvement", "Mécanique, visible côté cadran"],
      ["Cadran", "Squelette bleu à rouages apparents"],
      ["Bracelet", "Maillons acier, boucle déployante"],
    ],
    specifications: [
      ["Référence", "L’Horloger T-02"],
      ["Diamètre du cadran", "46 mm"],
      ["Épaisseur", "11 mm"],
      ["Mouvement", "Mécanique"],
      ["Boîtier", "Octogonal à facettes"],
      ["Cadran", "Squelette bleu"],
      ["Mécanique visible", "Rouages et balancier apparents"],
      ["Bracelet", "Maillons acier bleus"],
      ["Finition", "Acier brossé & bleu"],
      ["Couronne", "Vissée, finition acier"],
      ["Fond", "Transparent"],
      ["Verre", "Verre minéral"],
      ["Étanchéité annoncée", "30 m"],
      ["Fermoir", "Boucle déployante, acier inoxydable"],
      ["Style", "Contemporain"],
    ],
    features: [
      { title: "Le mouvement à ciel ouvert", copy: "Le cadran squelette laisse apparaître les rouages et le balancier : chaque regard ramène au geste mécanique." },
      { title: "Un boîtier qui accroche la lumière", copy: "Les facettes du boîtier octogonal, le bleu intense et les touches métalliques donnent du relief à la pièce." },
      { title: "Pensée jusque sous le cadran", copy: "Fond transparent, couronne vissée et boucle déployante complètent une construction pensée pour être regardée de tous les angles." },
    ],
    benefits: [
      { mark: "30 M", label: "Étanchéité annoncée", value: "30 mètres", copy: "Une protection conçue pour les usages courants au quotidien." },
      { mark: "MEC", label: "Mouvement", value: "Mécanique visible", copy: "Rouages et balancier restent au cœur de l’expérience visuelle." },
      { mark: "DOS", label: "Construction", value: "Fond transparent", copy: "Le mouvement se découvre aussi au revers du boîtier." },
      { mark: "46", label: "Présence", value: "Cadran 46 mm", copy: "Un format affirmé, pensé pour devenir le point de caractère d’une tenue." },
    ],
    editorial: {
      image: "/products/azur-squelette-lifestyle.png",
      alt: "Azur Squelette portée avec une tenue bleue habillée",
      eyebrow: "Le point de caractère",
      title: "Un bleu qui ne passe pas inaperçu.",
      copy: "La montre prend sa place avec une tenue monochrome, une chemise claire ou un vestiaire plus sobre. Elle n’a pas besoin d’en faire davantage.",
    },
  },
  {
    id: "tuma-03",
    slug: "eclipse-lunaire",
    name: "Éclipse Lunaire",
    reference: "T-03",
    image: "/products/eclipse-lunaire.png",
    gallery: [
      {
        src: "/products/eclipse-lunaire.png",
        alt: "Éclipse Lunaire portée au poignet, cadran argenté et bracelet cuir brun",
        label: "Portée",
        caption: "Éclipse Lunaire — vue portée",
      },
      {
        src: "/products/eclipse-lunaire-angle.png",
        alt: "Éclipse Lunaire vue de dessus sur un support en noyer",
        label: "Vue produit",
        caption: "Éclipse Lunaire — cadran et bracelet",
      },
      {
        src: "/products/eclipse-lunaire-lifestyle.png",
        alt: "Éclipse Lunaire portée en extérieur avec une veste brune",
        label: "Portée",
        caption: "Éclipse Lunaire — portée au quotidien",
      },
      {
        src: "/products/eclipse-lunaire-closeup.png",
        alt: "Gros plan sur Éclipse Lunaire au poignet avec une veste brune",
        label: "Détail",
        caption: "Éclipse Lunaire — phase de lune et ouverture mécanique",
      },
      {
        src: "/products/eclipse-lunaire-bureau.png",
        alt: "Éclipse Lunaire portée dans un environnement de bureau chaleureux",
        label: "Bureau",
        caption: "Éclipse Lunaire — élégance au quotidien",
      },
    ],
    variants: [
      {
        id: "argent-brun",
        label: "Argent & brun",
        description: "Cadran argenté, boîtier acier et cuir brun",
        swatch: "linear-gradient(135deg, #d6d7d4 0 58%, #70472f 58% 100%)",
        image: {
          src: "/products/eclipse-lunaire.png",
          alt: "Éclipse Lunaire argentée avec bracelet cuir brun",
          label: "Argent & brun",
          caption: "Éclipse Lunaire — acier clair et cuir brun",
        },
      },
    ],
    shortDescription: "Cadran argenté · bracelet cuir",
    description: "Un cadran argenté, une ouverture mécanique et une phase de lune qui captent la lumière avec retenue. Éclipse Lunaire se porte comme une pièce habillée, sans jamais être trop formelle.",
    price: 59_000,
    bracelet: "Cuir",
    finish: "Acier",
    size: "42,8 mm",
    dial: "Argent lunaire",
    tone: "argent",
    badge: "Dernières pièces",
    storyTitle: "Une allure habillée, éclairée par la lune.",
    styleNote: "Éclipse Lunaire combine l’éclat d’un cadran argenté, une ouverture mécanique et un bracelet brun. Une pièce calme, pensée pour les moments qui comptent.",
    highlights: [
      ["Complications", "Phase de lune, jour, date et mois"],
      ["Mouvement", "Mécanique à ouverture visible"],
      ["Bracelet", "Cuir véritable brun"],
    ],
    specifications: [
      ["Référence", "L’Horloger T-03"],
      ["Diamètre du cadran", "42,8 mm"],
      ["Épaisseur", "14 mm"],
      ["Mouvement", "Mécanique"],
      ["Boîtier", "Acier inoxydable poli"],
      ["Cadran", "Argenté à phase de lune"],
      ["Ouverture", "Mécanique apparente à 8 heures"],
      ["Indications visibles", "Phase de lune, jour, date et mois"],
      ["Luminescence", "Repères lumineux"],
      ["Bracelet", "Cuir véritable brun"],
      ["Fond", "Transparent"],
      ["Verre", "Verre minéral renforcé"],
      ["Étanchéité annoncée", "20 m"],
      ["Boucle", "Ardillon, acier inoxydable"],
      ["Style", "Habillé"],
    ],
    features: [
      { title: "La lune au centre du regard", copy: "La phase de lune anime le haut du cadran et donne à ce modèle sa profondeur singulière." },
      { title: "Une mécanique à contempler", copy: "L’ouverture à 8 heures et le fond transparent laissent voir l’architecture du mouvement des deux côtés." },
      { title: "Une montre complète", copy: "Jour, date, mois, repères lumineux et bracelet cuir véritable : chaque indication a sa place, sans alourdir la ligne." },
    ],
    benefits: [
      { mark: "20 M", label: "Étanchéité annoncée", value: "20 mètres", copy: "Une protection adaptée aux éclaboussures et aux gestes du quotidien." },
      { mark: "LUM", label: "Lecture", value: "Repères lumineux", copy: "Des index conçus pour rester lisibles quand la lumière baisse." },
      { mark: "LUNE", label: "Complication", value: "Phase de lune", copy: "Une indication visuelle qui donne sa profondeur au cadran." },
      { mark: "MEC", label: "Mouvement", value: "Mécanique apparent", copy: "L’ouverture à 8 heures et le fond transparent montrent la mécanique." },
    ],
    editorial: {
      image: "/products/eclipse-lunaire-lifestyle.png",
      alt: "Éclipse Lunaire portée en ville avec une veste brune",
      eyebrow: "Détails qui accompagnent le temps",
      title: "L’élégance, sans raideur.",
      copy: "Son acier clair et son cuir brun se glissent aussi bien sous une manche de veste que dans un vestiaire plus simple, le week-end.",
    },
  },
];

export const formatPrice = (price: number) =>
  `${new Intl.NumberFormat("fr-FR").format(price)} FCFA`;

export const getProduct = (slug: string) => products.find((product) => product.slug === slug);
