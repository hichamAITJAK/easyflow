<?php

namespace Database\Seeders\Support;

use Illuminate\Support\Str;

/**
 * Moroccan-looking demo data for FakerSeeder: names, phones, cities with
 * their neighbourhoods, addresses written the way customers type them,
 * and the kind of products Moroccan COD stores sell, priced in MAD.
 * Faker has no Moroccan locale, so the lists live here.
 */
class MoroccanData
{
    private const MALE_FIRST_NAMES = [
        'Mohamed', 'Youssef', 'Ayoub', 'Hamza', 'Amine', 'Omar', 'Anas', 'Mehdi', 'Hicham', 'Rachid',
        'Karim', 'Said', 'Abdelilah', 'Adil', 'Khalid', 'Mustapha', 'Ilyas', 'Zakaria', 'Soufiane', 'Othmane',
        'Badr', 'Yassine', 'Ismail', 'Hassan', 'Abderrahim', 'Nabil', 'Reda', 'Tarik', 'Jamal', 'Driss',
        'Brahim', 'Noureddine', 'Achraf', 'Walid', 'Ayman', 'Abdellah', 'Marouane', 'Taha', 'Saad', 'Hamid',
    ];

    private const FEMALE_FIRST_NAMES = [
        'Fatima Zahra', 'Khadija', 'Salma', 'Meryem', 'Imane', 'Hajar', 'Sara', 'Nadia', 'Fatiha', 'Zineb',
        'Asmae', 'Siham', 'Hanane', 'Loubna', 'Ghizlane', 'Kawtar', 'Oumaima', 'Houda', 'Malika', 'Naima',
        'Samira', 'Latifa', 'Rajae', 'Chaimae', 'Hiba', 'Aya', 'Yousra', 'Ikram', 'Wiam', 'Nora',
        'Btissam', 'Soukaina', 'Majda', 'Amal', 'Widad', 'Safae', 'Hafsa', 'Lamia', 'Rim', 'Najat',
    ];

    private const LAST_NAMES = [
        'El Amrani', 'Benali', 'Alaoui', 'Bennani', 'Tazi', 'Idrissi', 'Berrada', 'El Fassi', 'Chraibi', 'Ouazzani',
        'Benjelloun', 'Lahlou', 'Sebti', 'Kettani', 'El Idrissi', 'Bouzidi', 'El Mansouri', 'Ait Ali', 'Ait Taleb', 'Ouahbi',
        'Zerouali', 'Naciri', 'Cherkaoui', 'Hajji', 'Belkadi', 'El Khattabi', 'Saidi', 'Lamrani', 'Bakkali', 'Moussaoui',
        'Benkirane', 'Tahiri', 'Rahmouni', 'Haddad', 'Jaidi', 'El Ouardi', 'Boukhris', 'Filali', 'Skalli', 'Guessous',
        'Azzouzi', 'Ait Lahcen', 'Oubella', 'Agoumi', 'Bouhaddou', 'Essaidi', 'El Hachimi', 'Fikri', 'Ziani', 'Amzil',
    ];

    /**
     * City => [share of orders, neighbourhoods]. Casablanca leads, as it
     * does for every COD business in the country.
     *
     * @var array<string, array{0: int, 1: list<string>}>
     */
    private const CITIES = [
        'Casablanca' => [28, ['Maârif', 'Hay Mohammadi', 'Sidi Bernoussi', 'Aïn Sebaâ', 'Hay Hassani', 'Sidi Moumen', 'Derb Sultan', 'Aïn Chock', 'Bourgogne', 'Oulfa', 'Lissasfa', 'Sbata', 'Belvédère', 'Californie', 'Gauthier']],
        'Rabat' => [9, ['Agdal', 'Hay Riad', 'Yacoub El Mansour', 'Hassan', 'Takaddoum', 'Akkari', 'Souissi', 'Youssoufia']],
        'Salé' => [5, ['Tabriquet', 'Hay Salam', 'Sidi Moussa', 'Bettana', 'Hay Karima']],
        'Marrakech' => [9, ['Guéliz', 'Daoudiate', 'Massira', 'Sidi Youssef Ben Ali', "M'hamid", 'Hivernage', 'Azli']],
        'Fès' => [7, ['Saïss', 'Narjiss', 'Zouagha', 'Aïn Chkef', 'Bensouda', 'Atlas', 'Fès Jdid']],
        'Tanger' => [7, ['Malabata', 'Beni Makada', 'Mesnana', 'Iberia', 'Branes', 'Drissia', 'Souani']],
        'Agadir' => [5, ['Hay Dakhla', 'Talborjt', 'Charaf', 'Les Amicales', 'Tilila', 'Hay Mohammadi']],
        'Meknès' => [4, ['Hamria', 'Marjane', 'Bassatine', 'Sidi Baba', 'Zitoune']],
        'Oujda' => [4, ['Lazaret', 'Hay El Qods', 'Al Massira', 'Sidi Yahya']],
        'Kénitra' => [4, ['Bir Rami', 'Ouled Oujih', 'La Cigogne', 'Val Fleuri', 'Saknia']],
        'Tétouan' => [3, ['Mhannech', 'Touabel', 'Sania Ramel', 'Kouilma']],
        'Témara' => [3, ['Massira', 'Wifaq', 'Harhoura']],
        'Mohammédia' => [3, ['El Alia', 'Hay Rachidia', 'La Colline']],
        'El Jadida' => [2, ['Hay Najd', 'Hay Essalam', 'Lalla Zahra']],
        'Béni Mellal' => [2, ['Hay Riad', 'Ouled Hamdan', 'Hay El Hassani']],
        'Nador' => [2, ['Ouled Mimoun', 'Hay Al Matar', 'Laari Cheikh']],
        'Safi' => [2, ['Biada', 'Jrifat', 'Kawki']],
        'Laâyoune' => [1, ['Hay Al Wahda', 'Hay Moulay Rachid']],
    ];

    private const LOTISSEMENTS = ['Al Wifaq', 'Al Amal', 'Annour', 'Assalam', 'Al Massira', 'Ennasr', 'Al Firdaous', 'Al Majd'];

    private const RESIDENCES = ['Al Andalous', 'Riad Salam', 'Les Jardins', 'Nassim', 'Al Boustane', 'Yasmine', 'Al Qods'];

    private const LANDMARKS = [
        'près de la pharmacie', 'à côté de la mosquée', "en face de l'école", 'près du café', 'derrière Marjane',
        'près du hanout', 'à côté du hammam', 'en face de la poste', 'près de la station Afriquia',
    ];

    private const PHONE_PREFIXES = ['060', '061', '062', '063', '064', '065', '066', '067', '068', '069', '070', '071', '077', '078'];

    private const ORDER_NOTES = [
        'Appeler après 18h',
        'Livraison au travail',
        'Le client veut ouvrir le colis avant de payer',
        'Rappeler demain matin',
        'Client joignable sur WhatsApp seulement',
        'Livrer le samedi',
        'Numéro du mari en secours',
        'Demande la couleur noire si dispo',
        'Client pressé, livrer vite',
        'Ne pas appeler avant 10h',
    ];

    /**
     * The catalogue: name, price in MAD, and at most one variant axis.
     *
     * @var list<array{name: string, price: int, axis?: array{0: string, 1: list<string>}}>
     */
    public const PRODUCTS = [
        ['name' => 'Montre Connectée Ultra 8', 'price' => 249, 'axis' => ['Couleur', ['Noir', 'Argent', 'Rose']]],
        ['name' => 'Écouteurs sans fil Pro 6', 'price' => 199, 'axis' => ['Couleur', ['Blanc', 'Noir']]],
        ['name' => "Huile d'Argan Bio 100ml", 'price' => 149],
        ['name' => 'Sérum Vitamine C', 'price' => 179],
        ['name' => 'Lisseur Cheveux Céramique', 'price' => 299, 'axis' => ['Couleur', ['Noir', 'Rose']]],
        ['name' => 'Tondeuse Barbe Rechargeable', 'price' => 229],
        ['name' => 'Mini Blender Portable', 'price' => 189, 'axis' => ['Couleur', ['Blanc', 'Rose', 'Bleu']]],
        ['name' => 'Friteuse à Air 5L', 'price' => 699, 'axis' => ['Couleur', ['Noir', 'Blanc']]],
        ['name' => 'Aspirateur Voiture sans fil', 'price' => 249],
        ['name' => 'Mini Projecteur HD', 'price' => 899],
        ['name' => 'Lampe Solaire LED Extérieur', 'price' => 199],
        ['name' => 'Caftan Brodé Femme', 'price' => 599, 'axis' => ['Taille', ['S', 'M', 'L', 'XL']]],
        ['name' => 'Djellaba Homme Lin', 'price' => 449, 'axis' => ['Taille', ['M', 'L', 'XL']]],
        ['name' => 'Babouches Cuir de Fès', 'price' => 179, 'axis' => ['Pointure', ['39', '40', '41', '42', '43']]],
        ['name' => 'Sac à Main Cuir', 'price' => 349, 'axis' => ['Couleur', ['Noir', 'Camel', 'Bordeaux']]],
        ['name' => 'Service à Thé Marocain 6 verres', 'price' => 299, 'axis' => ['Couleur', ['Doré', 'Argent']]],
        ['name' => 'Tapis de Prière Mousse', 'price' => 159, 'axis' => ['Couleur', ['Vert', 'Bleu', 'Bordeaux']]],
        ['name' => 'Kit Ghassoul & Savon Beldi', 'price' => 129],
        ['name' => 'Coussin Orthopédique', 'price' => 199],
        ['name' => 'Ceinture Amincissante', 'price' => 179, 'axis' => ['Taille', ['M', 'L', 'XL']]],
        ['name' => 'Masseur Cervical Électrique', 'price' => 279],
        ['name' => 'Support Téléphone Voiture', 'price' => 99],
        ['name' => 'Power Bank Solaire 20000mAh', 'price' => 249],
        ['name' => 'Bouilloire Électrique Inox', 'price' => 189],
        ['name' => 'Set Couteaux Cuisine 6 pièces', 'price' => 219],
        ['name' => 'Brosse Lissante Chauffante', 'price' => 249],
        ['name' => 'Parfum Musc Tahara', 'price' => 99],
        ['name' => 'Caméra Surveillance Wi-Fi', 'price' => 399],
        ['name' => 'Produit Test - Échantillon', 'price' => 10],
        ['name' => 'Produit Test - Livraison', 'price' => 20],
    ];

    /** @return array{first: string, last: string, name: string} */
    public static function person(?bool $female = null): array
    {
        $female ??= fake()->boolean(55);
        $first = fake()->randomElement($female ? self::FEMALE_FIRST_NAMES : self::MALE_FIRST_NAMES);
        $last = fake()->randomElement(self::LAST_NAMES);

        return ['first' => $first, 'last' => $last, 'name' => "{$first} {$last}"];
    }

    public static function name(?bool $female = null): string
    {
        return self::person($female)['name'];
    }

    /** A mobile number as the platform stores it: 06XXXXXXXX / 07XXXXXXXX. */
    public static function phone(): string
    {
        return fake()->randomElement(self::PHONE_PREFIXES).fake()->numerify('#######');
    }

    /** A city, weighted by how many orders it really sends. */
    public static function city(): string
    {
        $weights = array_map(fn (array $city) => $city[0], self::CITIES);
        $pick = mt_rand(1, array_sum($weights));

        foreach ($weights as $city => $weight) {
            $pick -= $weight;

            if ($pick <= 0) {
                return $city;
            }
        }

        return 'Casablanca';
    }

    /** An address in $city, written the way customers type it. */
    public static function address(string $city): string
    {
        $quartier = fake()->randomElement(self::CITIES[$city][1] ?? ['Centre ville']);
        $n = rand(1, 120);
        $m = rand(1, 80);

        $address = match (rand(1, 4)) {
            1 => "{$quartier}, Rue {$n} N° {$m}",
            2 => 'Lotissement '.fake()->randomElement(self::LOTISSEMENTS)." N° {$m}, {$quartier}",
            3 => 'Résidence '.fake()->randomElement(self::RESIDENCES).", Imm {$n} Appt {$m}, {$quartier}",
            default => "{$quartier}, Derb {$n} N° {$m}",
        };

        return fake()->boolean(25) ? $address.', '.fake()->randomElement(self::LANDMARKS) : $address;
    }

    /** A Moroccan mobile/ADSL address (Maroc Telecom, Orange, inwi blocks). */
    public static function ip(): string
    {
        $first = fake()->randomElement(['105', '41', '196', '102', '160']);

        return $first.'.'.rand(0, 255).'.'.rand(0, 255).'.'.rand(1, 254);
    }

    public static function orderNote(): string
    {
        return fake()->randomElement(self::ORDER_NOTES);
    }

    /** firstname.lastname@easyflow.ma, for staff accounts. */
    public static function staffEmail(array $person): string
    {
        $slug = fn (string $part) => str_replace(' ', '', strtolower(Str::ascii($part)));

        return $slug($person['first']).'.'.$slug($person['last']).'@easyflow.ma';
    }
}
