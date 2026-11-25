<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Services\StockMovementService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use RuntimeException;

class DemoStockSeeder extends Seeder
{
    private const string LATITUDE_SKU = 'PC-DELL-001';

    private const string SALMA_EMAIL = 'salma.elmansouri@stockflow.test';

    private const string YASSINE_EMAIL = 'yassine.amrani@stockflow.test';

    public function run(StockMovementService $stockMovements): void
    {
        $connection = DB::connection();
        if (! app()->environment('local')
            || $connection->getDriverName() !== 'mysql'
            || ! in_array($connection->getConfig('host'), ['127.0.0.1', 'localhost', '::1'], true)) {
            throw new RuntimeException('DemoStockSeeder requires local Laravel and a loopback MySQL server.');
        }

        $admin = User::query()
            ->where('email', 'admin@stockflow.test')
            ->where('role', 'admin')
            ->where('is_active', true)
            ->firstOr(fn () => throw new RuntimeException('Le compte administrateur de démonstration actif est requis.'));
        $salma = $this->activeEmployee(self::SALMA_EMAIL);
        $yassine = $this->activeEmployee(self::YASSINE_EMAIL);
        $actors = ['admin' => $admin, 'salma' => $salma, 'yassine' => $yassine];

        $connection->transaction(function () use ($actors, $stockMovements): void {
            $categories = [];
            $products = [];

            foreach ($this->catalog() as $sku => [$name, $brand, $categoryName, $price, $minimum, $active, $imageUrl]) {
                $category = $categories[$categoryName] ??= Category::query()->firstOrCreate(['name' => $categoryName]);
                $product = Product::query()->firstOrCreate(['sku' => $sku], [
                    'name' => $name,
                    'brand' => $brand,
                    'category_id' => $category->id,
                    'unit_price' => $price,
                    'min_stock' => $minimum,
                    'is_active' => $active,
                    'image_url' => $imageUrl,
                ]);

                if ($product->name !== $name || $product->brand !== $brand) {
                    throw new RuntimeException("Le SKU {$sku} appartient à une autre référence.");
                }

                if ($sku === self::LATITUDE_SKU && $product->image_url === 'https://example.com/dell.jpg') {
                    $product->update(['image_url' => $imageUrl]);
                }

                if ($sku === self::LATITUDE_SKU && $product->category->name === 'Imprimantes') {
                    $product->update(['category_id' => $category->id]);
                }

                $products[$sku] = $product;
            }

            foreach ($this->movements() as [$sku, $actor, $type, $quantity, $note]) {
                $stockMovements->create([
                    'product_id' => $products[$sku]->id,
                    'type' => $type,
                    'quantity' => $quantity,
                    'note' => $note,
                    'idempotency_key' => Uuid::uuid5(Uuid::NAMESPACE_URL, "stockflow:demo-stock:v1:{$sku}:{$type}")->toString(),
                ], $actors[$actor]);
            }
        }, 3);

        $this->command?->info('Catalogue et mouvements de démonstration prêts.');
    }

    private function activeEmployee(string $email): User
    {
        return User::query()
            ->where('email', $email)
            ->where('role', 'employee')
            ->where('is_active', true)
            ->firstOr(fn () => throw new RuntimeException("Créez le compte employé actif {$email} via l’interface administrateur."));
    }

    /** @return array<string, array{string, string, string, int, int, bool, string}> */
    private function catalog(): array
    {
        return [
            // Nom, marque, catégorie, prix en MAD, seuil, statut et photo.
            self::LATITUDE_SKU => [
                'Dell Latitude 5420', 'Dell', 'Ordinateurs portables', 4500, 2, true,
                'https://i.dell.com/is/image/DellContent/content/dam/ss2/product-images/dell-client-products/notebooks/latitude-notebooks/14-5420/global-spi/ng/notebook-latitude-5420-nt-relsize-500-ng.psd?fmt=jpg&wid=500&hei=191',
            ],
            'DEMO-PC-7010' => [
                'Dell OptiPlex Micro 7010', 'Dell', 'Ordinateurs de bureau', 5290, 3, true,
                'https://i.dell.com/is/image/DellContent/content/dam/ss2/product-images/dell-client-products/desktops/optiplex-desktops/optiplex-d13-micro/spi-standard/ng/desktop-optiplex-7010-mff-black-relsize-500-ng.psd?fmt=jpg&wid=500&hei=304',
            ],
            'DEMO-ECR-P2422H' => [
                'Dell P2422H 24 pouces', 'Dell', 'Écrans', 1690, 4, true,
                'https://i.dell.com/is/image/DellContent/content/dam/ss2/product-images/dell-client-products/peripherals/monitors/p-series/p2422h/spi/ng/monitor-p2422h-relsize-500-ng.psd?qlt=95&fit=constrain,1&hei=344&wid=500&fmt=jpg',
            ],
            'DEMO-IMP-4001DN' => [
                'HP LaserJet Pro 4001dn', 'HP', 'Imprimantes', 3190, 2, true,
                'https://hp.widen.net/content/pl8axi5lpt/webp/pl8axi5lpt.png?w=573&h=430&dpi=72&color=ffffff00',
            ],
            'DEMO-CLA-K120' => [
                'Logitech K120', 'Logitech', 'Claviers', 119, 10, true,
                'https://resource.logitech.com/c_fill,q_auto,f_auto,dpr_1.0/d_transparent.gif/content/dam/logitech/en/products/keyboards/k120/gallery/k120-gallery-01-new.png',
            ],
            'DEMO-SOU-M185' => [
                'Logitech M185', 'Logitech', 'Souris', 149, 8, true,
                'https://resource.logitech.com/c_fill,q_auto,f_auto,dpr_1.0/d_transparent.gif/content/dam/logitech/en/products/mice/m185/gallery/2025-update-assets/m185-grey-top-angle-1.png',
            ],
            'DEMO-SSD-NV3' => [
                'Kingston NV3 1 To', 'Kingston', 'Stockage', 749, 5, true,
                'https://media.kingston.com/kingston/key-features/ktc-keyfeatures-ssd-snv3s-kf1-lg.jpg',
            ],
            'DEMO-NET-AX23' => [
                'TP-Link Archer AX23', 'TP-Link', 'Réseau', 649, 6, true,
                'https://static.tp-link.com/1large_1627273016330o.jpg',
            ],
            'DEMO-NET-SG108' => [
                'TP-Link TL-SG108', 'TP-Link', 'Réseau', 329, 4, true,
                'https://static.tp-link.com/TL-SG108(UN)3.0_01_large_1516617291361v.jpg',
            ],
            'DEMO-AUD-H390' => [
                'Logitech H390', 'Logitech', 'Périphériques', 349, 4, true,
                'https://resource.logitech.com/c_fill,q_auto,f_auto,dpr_1.0/d_transparent.gif/content/dam/logitech/en/products/headsets/h390-usb-headset/gallery/h390-frontview-gallery-black.png',
            ],
            'DEMO-IMP-L2460DW' => [
                'Brother HL-L2460DW', 'Brother', 'Imprimantes', 2290, 3, true,
                'https://assets.brother.com/transform/4507e3c5-f42c-40fa-8222-ea80d1f14cc5/20525_HL-L2460DW_Spinners_1-jpg?io=transform%3Afit%2Cwidth%3A480',
            ],
            'DEMO-USB-DTX64' => [
                'Kingston DataTraveler Exodia 64 Go', 'Kingston', 'Stockage', 79, 10, true,
                'https://media.kingston.com/kingston/product/ktc-product-usb-dtx-64gb-1-zm-lg.jpg',
            ],
            'DEMO-ECR-P2723D' => [
                'Dell P2723D 27 pouces', 'Dell', 'Écrans', 2720, 3, true,
                'https://i.dell.com/is/image/DellContent/content/dam/ss2/product-images/dell-client-products/peripherals/monitors/p-series/p2723d/pdp/monitor-p2723d-campaign-hero-504x350-ng.psd?fmt=png-alpha&wid=504&hei=350',
            ],
            'DEMO-CLA-K380S' => [
                'Logitech Pebble Keys 2 K380s', 'Logitech', 'Claviers', 429, 3, true,
                'https://resource.logitech.com/c_fill,q_auto,f_auto,dpr_1.0/d_transparent.gif/content/dam/logitech/en/products/keyboards/pebble-keys-2-k380s/2026/gallery/tonal-rose/pebble-keys-k380s-2-tonal-rose-top-angle-gallery-1.png',
            ],
            'DEMO-VID-C920S' => [
                'Logitech C920s Pro HD', 'Logitech', 'Périphériques', 699, 3, true,
                'https://resource.logitech.com/c_fill,q_auto,f_auto,dpr_1.0/d_transparent.gif/content/dam/logitech/en/products/webcams/c920s/gallery/c920s-gallery-1.png',
            ],
            'DEMO-ADP-UE300' => [
                'TP-Link UE300', 'TP-Link', 'Accessoires', 179, 4, true,
                'https://static.tp-link.com/1_large_1557038845349f.jpg',
            ],
            'DEMO-ADP-UB500' => [
                'TP-Link UB500', 'TP-Link', 'Accessoires', 119, 4, true,
                'https://static.tp-link.com/upload/image-line/UB500_UN_3.0_overview_01_large_20250624030858j.jpg',
            ],
            'DEMO-VID-C270' => [
                'Logitech C270 HD', 'Logitech', 'Périphériques', 249, 2, false,
                'https://resource.logitech.com/c_fill,q_auto,f_auto,dpr_1.0/d_transparent.gif/content/dam/products/logitech/webcams/c270-hd-webcam/gallery/c270-hd-webcam-1-0224.png',
            ],
            'DEMO-AUD-H111' => [
                'Logitech H111', 'Logitech', 'Périphériques', 139, 3, false,
                'https://resource.logitech.com/c_fill,q_auto,f_auto,dpr_1.0/d_transparent.gif/content/dam/logitech/en/products/video-conferencing/h111/gallery/h111-gallery-01.png',
            ],
            'DEMO-NET-SG105' => [
                'TP-Link TL-SG105', 'TP-Link', 'Réseau', 249, 3, true,
                'https://static.tp-link.com/upload/image-line/TL-SG105(UN)6_1_large_20231020072105g.jpg',
            ],
        ];
    }

    /** @return list<array{string, 'admin'|'salma'|'yassine', 'in'|'out', int, string}> */
    private function movements(): array
    {
        return [
            // SKU, responsable, type, quantité et note.
            ['DEMO-PC-7010', 'admin', 'in', 8, 'Réception des postes bureautiques du lot Casablanca.'],
            ['DEMO-ECR-P2422H', 'salma', 'in', 15, 'Livraison des écrans pour le renouvellement du parc.'],
            ['DEMO-IMP-4001DN', 'admin', 'in', 3, 'Réception des imprimantes pour les agences.'],
            ['DEMO-CLA-K120', 'salma', 'in', 48, 'Réapprovisionnement du stock de claviers filaires.'],
            ['DEMO-PC-7010', 'salma', 'out', 2, 'Affectation de deux postes au service comptabilité.'],
            ['DEMO-SOU-M185', 'yassine', 'in', 32, 'Réception du lot de souris pour les postes de travail.'],
            ['DEMO-IMP-4001DN', 'yassine', 'out', 3, 'Distribution aux trois agences régionales.'],
            ['DEMO-CLA-K120', 'yassine', 'out', 4, 'Remplacement de quatre claviers au service accueil.'],
            ['DEMO-SSD-NV3', 'admin', 'in', 14, 'Approvisionnement des SSD pour les mises à niveau.'],
            ['DEMO-NET-AX23', 'yassine', 'in', 6, 'Réception des routeurs du lot réseau.'],
            ['DEMO-NET-SG108', 'admin', 'in', 13, 'Approvisionnement des commutateurs de huit ports.'],
            ['DEMO-AUD-H390', 'salma', 'in', 12, 'Livraison des casques USB pour le support.'],
            ['DEMO-IMP-L2460DW', 'admin', 'in', 2, 'Réception de deux imprimantes de secours.'],
            ['DEMO-USB-DTX64', 'yassine', 'in', 28, 'Approvisionnement des clés USB de service.'],
            ['DEMO-ECR-P2723D', 'admin', 'in', 7, 'Réception des écrans QHD pour le bureau d’études.'],
            ['DEMO-AUD-H390', 'salma', 'out', 5, 'Affectation de casques à l’équipe support.'],
            ['DEMO-CLA-K380S', 'salma', 'in', 9, 'Réception des claviers Bluetooth pour les postes partagés.'],
            ['DEMO-VID-C920S', 'yassine', 'in', 8, 'Réception des webcams pour les salles de réunion.'],
            ['DEMO-ADP-UE300', 'admin', 'in', 5, 'Réception des adaptateurs USB vers Ethernet.'],
            ['DEMO-ADP-UB500', 'salma', 'in', 11, 'Approvisionnement des adaptateurs Bluetooth.'],
            ['DEMO-NET-SG105', 'yassine', 'in', 10, 'Livraison des commutateurs destinés aux petits bureaux.'],
            ['DEMO-ADP-UE300', 'salma', 'out', 5, 'Installation des adaptateurs sur les postes de formation.'],
        ];
    }
}
