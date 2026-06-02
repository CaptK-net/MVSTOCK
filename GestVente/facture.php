<?php
// ============================================================
//  MVSTOCK — Génération de facture PDF
//  Accès : facture.php?id=ID_VENTE
// ============================================================

session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

require_once 'config.php';
require_once 'fpdf.php';

// ── Récupère l'ID de la vente ────────────────────────────────
$id_vente = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id_vente <= 0) {
    die('Identifiant de vente invalide.');
}

// ── Données de la vente ──────────────────────────────────────
$res = mysqli_query($conn,
    "SELECT v.*,
            CONCAT(c.prenom, ' ', c.nom) AS client_nom,
            c.telephone                  AS client_tel,
            c.email                      AS client_email,
            CONCAT(u.prenom, ' ', u.nom) AS agent_nom
     FROM vente v
     LEFT JOIN client      c ON v.id_client      = c.id_client
     LEFT JOIN utilisateur u ON v.id_utilisateur = u.id_utilisateur
     WHERE v.id_vente = $id_vente"
);
if (!$res || mysqli_num_rows($res) === 0) {
    die('Vente introuvable.');
}
$vente = mysqli_fetch_assoc($res);

// ── Lignes de la vente ───────────────────────────────────────
$res_lignes = mysqli_query($conn,
    "SELECT p.designation,
            lv.quantite,
            lv.prix_unitaire,
            (lv.quantite * lv.prix_unitaire) AS sous_total
     FROM ligne_vente lv
     JOIN produit p ON lv.id_produit = p.id_produit
     WHERE lv.id_vente = $id_vente"
);
$lignes = [];
while ($row = mysqli_fetch_assoc($res_lignes)) {
    $lignes[] = $row;
}

// ============================================================
//  CLASSE PDF PERSONNALISÉE
// ============================================================
class FactureMVSTOCK extends FPDF {

    // ── Couleurs MVSTOCK ─────────────────────────────────────
    // Noir : 26, 26, 26   |   Or : 201, 162, 39   |   Gris clair : 245, 245, 245
    // ────────────────────────────────────────────────────────

    function Header() {
        // Bande noire en haut
        $this->SetFillColor(26, 26, 26);
        $this->Rect(0, 0, 210, 38, 'F');

        // Titre MVSTOCK
        $this->SetFont('Helvetica', 'B', 28);
        $this->SetTextColor(201, 162, 39);   // or
        $this->SetXY(12, 6);
        $this->Cell(100, 12, 'MVSTOCK', 0, 0, 'L');

        // Slogan
        $this->SetFont('Helvetica', 'I', 9);
        $this->SetTextColor(180, 180, 180);
        $this->SetXY(12, 20);
        $this->Cell(100, 6, 'Sell fast, restock faster', 0, 0, 'L');

        // Numéro de facture (droite)
        $this->SetFont('Helvetica', 'B', 11);
        $this->SetTextColor(201, 162, 39);
        $this->SetXY(120, 8);
        $this->Cell(78, 7, 'INVOICE', 0, 2, 'R');
        $this->SetFont('Helvetica', '', 9);
        $this->SetTextColor(210, 210, 210);
        $this->SetX(120);
        $this->Cell(78, 5, 'N  ' . str_pad($this->id_vente, 6, '0', STR_PAD_LEFT), 0, 0, 'R');

        // Ligne dorée sous le header
        $this->SetDrawColor(201, 162, 39);
        $this->SetLineWidth(0.6);
        $this->Line(0, 38, 210, 38);

        $this->Ln(14);
    }

    function Footer() {
        $this->SetY(-18);
        // Ligne dorée
        $this->SetDrawColor(201, 162, 39);
        $this->SetLineWidth(0.4);
        $this->Line(10, $this->GetY(), 200, $this->GetY());
        $this->Ln(2);
        $this->SetFont('Helvetica', 'I', 8);
        $this->SetTextColor(150, 150, 150);
        $this->Cell(95, 5, 'MVSTOCK — Sell fast, restock faster', 0, 0, 'L');
        $this->Cell(95, 5, 'Page ' . $this->PageNo(), 0, 0, 'R');
    }

    // Propriété pour passer l'id_vente au header
    public $id_vente = 0;
}

// ============================================================
//  GÉNÉRATION DU PDF
// ============================================================
$pdf = new FactureMVSTOCK();
$pdf->id_vente = $id_vente;
$pdf->AddPage();
$pdf->SetAutoPageBreak(true, 25);

// ── Bloc infos vente + client ────────────────────────────────
// Strip accents helper — FPDF basic fonts are Latin-1, not UTF-8
function strip_accents($str) {
    $search  = ['à','â','ä','á','ã','å','è','é','ê','ë','î','ï','ì','í','ô','ö','ò','ó','õ','ù','û','ü','ú','ç','ñ',
                'À','Â','Ä','Á','Ã','Å','È','É','Ê','Ë','Î','Ï','Ì','Í','Ô','Ö','Ò','Ó','Õ','Ù','Û','Ü','Ú','Ç','Ñ'];
    $replace = ['a','a','a','a','a','a','e','e','e','e','i','i','i','i','o','o','o','o','o','u','u','u','u','c','n',
                'A','A','A','A','A','A','E','E','E','E','I','I','I','I','O','O','O','O','O','U','U','U','U','C','N'];
    return str_replace($search, $replace, $str);
}

// Payment method in English
$payment_labels = ['especes' => 'Cash', 'carte' => 'Card', 'virement' => 'Bank Transfer'];

$date_formatee = date('m/d/Y H:i', strtotime($vente['date_vente']));
$client_nom    = $vente['client_nom']   ? strip_accents($vente['client_nom'])   : 'Anonymous';
$client_tel    = $vente['client_tel']   ? $vente['client_tel']                  : 'N/A';
$client_email  = $vente['client_email'] ? $vente['client_email']                : 'N/A';
$agent_nom     = $vente['agent_nom']    ? strip_accents($vente['agent_nom'])    : 'N/A';
$mode_paiement = isset($payment_labels[$vente['mode_paiement']]) ? $payment_labels[$vente['mode_paiement']] : ucfirst($vente['mode_paiement']);

// Colonne gauche — Infos vente
$pdf->SetFont('Helvetica', 'B', 9);
$pdf->SetTextColor(26, 26, 26);
$pdf->SetFillColor(245, 245, 245);
$pdf->SetDrawColor(220, 220, 220);

// Cadre gauche
$pdf->SetXY(10, $pdf->GetY());
$pdf->SetFont('Helvetica', 'B', 9);
$pdf->SetFillColor(26, 26, 26);
$pdf->SetTextColor(201, 162, 39);
$pdf->Cell(88, 7, '  SALE INFORMATION', 1, 2, 'L', true);

$pdf->SetFont('Helvetica', '', 9);
$pdf->SetTextColor(26, 26, 26);
$pdf->SetFillColor(248, 248, 248);

$infos_vente = [
    ['Sale No.',     '#' . str_pad($id_vente, 6, '0', STR_PAD_LEFT)],
    ['Date',         $date_formatee],
    ['Payment',      $mode_paiement],
    ['Agent',        $agent_nom],
];
foreach ($infos_vente as $i => $row) {
    $fill = $i % 2 === 0;
    $pdf->SetFillColor($fill ? 248 : 255, $fill ? 248 : 255, $fill ? 248 : 255);
    $pdf->SetX(10);
    $pdf->Cell(40, 6, '  ' . $row[0], 'LR', 0, 'L', true);
    $pdf->Cell(48, 6, $row[1], 'LR', 1, 'L', true);
}
// Ligne de fermeture
$pdf->SetX(10);
$pdf->Cell(88, 0, '', 'T', 1);

$y_after_left = $pdf->GetY();

// Cadre droit — Infos client
$pdf->SetXY(112, 52);
$pdf->SetFont('Helvetica', 'B', 9);
$pdf->SetFillColor(26, 26, 26);
$pdf->SetTextColor(201, 162, 39);
$pdf->Cell(88, 7, '  CUSTOMER', 1, 2, 'L', true);

$pdf->SetFont('Helvetica', '', 9);
$pdf->SetTextColor(26, 26, 26);

$infos_client = [
    ['Name',      $client_nom],
    ['Phone',     $client_tel],
    ['Email',     $client_email],
];
foreach ($infos_client as $i => $row) {
    $fill = $i % 2 === 0;
    $pdf->SetFillColor($fill ? 248 : 255, $fill ? 248 : 255, $fill ? 248 : 255);
    $pdf->SetX(112);
    $pdf->Cell(36, 6, '  ' . $row[0], 'LR', 0, 'L', true);
    $pdf->Cell(52, 6, $row[1], 'LR', 1, 'L', true);
}
$pdf->SetX(112);
$pdf->Cell(88, 0, '', 'T', 1);

// Reprend après le plus haut des deux blocs
$pdf->SetY(max($y_after_left, $pdf->GetY()) + 8);

// ── Tableau des produits ─────────────────────────────────────
// En-tête du tableau
$pdf->SetFont('Helvetica', 'B', 9);
$pdf->SetFillColor(26, 26, 26);
$pdf->SetTextColor(201, 162, 39);
$pdf->SetDrawColor(51, 51, 51);
$pdf->SetLineWidth(0.3);

$pdf->SetX(10);
$pdf->Cell(84, 8, '  Product',           'TLRB', 0, 'L', true);
$pdf->Cell(26, 8, 'Qty',                'TLRB', 0, 'C', true);
$pdf->Cell(36, 8, 'Unit Price',         'TLRB', 0, 'C', true);
$pdf->Cell(34, 8, 'Subtotal',           'TLRB', 1, 'C', true);

// Lignes produits
$pdf->SetFont('Helvetica', '', 9);
$pdf->SetTextColor(26, 26, 26);
$pdf->SetDrawColor(204, 204, 204);

foreach ($lignes as $i => $ligne) {
    $fill = $i % 2 === 0;
    $pdf->SetFillColor($fill ? 255 : 245, $fill ? 255 : 245, $fill ? 255 : 245);
    $pdf->SetX(10);
    $pdf->Cell(84, 7, '  ' . strip_accents($ligne['designation']), 'LR',  0, 'L', true);
    $pdf->Cell(26, 7, $ligne['quantite'],                              'LR',  0, 'C', true);
    $pdf->Cell(36, 7, number_format($ligne['prix_unitaire'], 2) . ' $', 'LR', 0, 'R', true);
    $pdf->Cell(34, 7, number_format($ligne['sous_total'],    2) . ' $', 'LR', 1, 'R', true);
}

// Ligne de fermeture du tableau
$pdf->SetX(10);
$pdf->Cell(180, 0, '', 'T');
$pdf->Ln(0);

// ── Bloc TOTAL ───────────────────────────────────────────────
$pdf->Ln(4);
$pdf->SetX(120);
$pdf->SetFont('Helvetica', '', 9);
$pdf->SetFillColor(245, 245, 245);
$pdf->SetTextColor(80, 80, 80);
$pdf->Cell(35, 7, 'Subtotal', 'LTR', 0, 'L', true);
$pdf->Cell(35, 7, number_format($vente['montant_total'], 2) . ' $', 'LTR', 1, 'R', true);

$pdf->SetX(120);
$pdf->Cell(35, 7, 'Tax (0%)', 'LR', 0, 'L', true);
$pdf->Cell(35, 7, '0.00 $', 'LR', 1, 'R', true);

// Total final en noir/or
$pdf->SetX(120);
$pdf->SetFont('Helvetica', 'B', 11);
$pdf->SetFillColor(26, 26, 26);
$pdf->SetTextColor(201, 162, 39);
$pdf->Cell(35, 9, '  TOTAL', 'TLRB', 0, 'L', true);
$pdf->Cell(35, 9, number_format($vente['montant_total'], 2) . ' $', 'TLRB', 1, 'R', true);

// ── Message de remerciement ──────────────────────────────────
$pdf->Ln(10);
$pdf->SetFont('Helvetica', 'I', 9);
$pdf->SetTextColor(120, 120, 120);
$pdf->SetX(10);
$pdf->Cell(190, 6, 'Thank you for your business. For any questions, please contact your MVSTOCK agent.', 0, 1, 'C');

// ── Bande noire décorative en bas ────────────────────────────
$pdf->SetY(-30);
$pdf->SetFillColor(26, 26, 26);
$pdf->Rect(0, $pdf->GetY(), 210, 12, 'F');
$pdf->SetFont('Helvetica', 'B', 8);
$pdf->SetTextColor(201, 162, 39);
$pdf->SetX(10);
$pdf->Cell(190, 12, 'MVSTOCK  |  Sell fast, restock faster  |  Cleanify Lebanon', 0, 0, 'C');

// ── Sortie du PDF ────────────────────────────────────────────
$nom_fichier = 'Invoice_MVSTOCK_' . str_pad($id_vente, 6, '0', STR_PAD_LEFT) . '.pdf';
$pdf->Output('D', $nom_fichier);   // 'D' = téléchargement direct
?>
