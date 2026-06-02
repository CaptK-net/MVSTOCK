<?php
// ============================================================
//  facture.php — MVSTOCK PDF INVOICE GENERATOR
//
//  Access: facture.php?id=SALE_ID
//
//  This file generates and immediately downloads a PDF invoice
//  for a given sale. It uses the FPDF library (fpdf.php) which
//  is a pure-PHP PDF generator that requires no external tools.
//
//  Layout (single A4 page, portrait):
//    - Header  : black band with MVSTOCK logo + invoice number
//    - Info block : one merged table — sale info (left) | customer (right)
//    - Product table : all line items + subtotal / tax / TOTAL rows attached
//    - Thank-you note
//    - Footer  : thin gold line + page info
// ============================================================

// session_start() resumes the PHP session so we can check if the user
// is logged in before generating the document.
session_start();

// If no user is logged in, redirect to the login page immediately.
// This prevents anyone from downloading invoices without authentication.
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

// config.php gives us $conn — the database connection variable.
require_once 'config.php';

// fpdf.php is the FPDF library. It provides the FPDF class we extend below.
require_once 'fpdf.php';

// ── READ THE SALE ID FROM THE URL ────────────────────────────
// The URL looks like: facture.php?id=5
// isset() checks the parameter exists; (int) converts it to a safe integer.
$id_vente = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// If the ID is 0 or negative, there is nothing to generate. Stop here.
if ($id_vente <= 0) {
    die('Invalid sale ID.');
}

// ── FETCH THE SALE HEADER FROM THE DATABASE ──────────────────
// We JOIN three tables:
//   vente       : the sale itself (date, total, payment method)
//   client      : the customer linked to this sale (name, phone, email)
//   utilisateur : the agent who recorded the sale (name)
// LEFT JOIN means the sale is still returned even if client or agent is NULL.
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

// If the query failed or returned 0 rows, the sale does not exist.
if (!$res || mysqli_num_rows($res) === 0) {
    die('Sale not found.');
}

// Read the single result row as a PHP associative array.
$vente = mysqli_fetch_assoc($res);

// ── FETCH THE PRODUCT LINES FOR THIS SALE ───────────────────
// Each ligne_vente row represents one product in the sale.
// sous_total is calculated directly in SQL: quantity x unit_price.
$res_lignes = mysqli_query($conn,
    "SELECT p.designation,
            lv.quantite,
            lv.prix_unitaire,
            (lv.quantite * lv.prix_unitaire) AS sous_total
     FROM ligne_vente lv
     JOIN produit p ON lv.id_produit = p.id_produit
     WHERE lv.id_vente = $id_vente"
);

// Store all product lines in a PHP array so we can loop through them
// later during PDF generation.
$lignes = [];
while ($row = mysqli_fetch_assoc($res_lignes)) {
    $lignes[] = $row;
}

// ── ACCENT-STRIPPING HELPER ──────────────────────────────────
// FPDF's built-in Helvetica/Times/Courier fonts use the ISO-8859-1
// (Latin-1) character set. Accented characters like é, à, ç exist
// in Latin-1 but the PHP strings from MySQL are UTF-8. Rather than
// embedding a full Unicode font, we simply replace accented letters
// with their unaccented equivalents for a clean single-page output.
function strip_accents($str) {
    // $search: all common accented UTF-8 characters
    $search  = [
        'à','â','ä','á','ã','å',
        'è','é','ê','ë',
        'î','ï','ì','í',
        'ô','ö','ò','ó','õ',
        'ù','û','ü','ú',
        'ç','ñ',
        'À','Â','Ä','Á','Ã','Å',
        'È','É','Ê','Ë',
        'Î','Ï','Ì','Í',
        'Ô','Ö','Ò','Ó','Õ',
        'Ù','Û','Ü','Ú',
        'Ç','Ñ'
    ];
    // $replace: plain ASCII equivalents in the same order
    $replace = [
        'a','a','a','a','a','a',
        'e','e','e','e',
        'i','i','i','i',
        'o','o','o','o','o',
        'u','u','u','u',
        'c','n',
        'A','A','A','A','A','A',
        'E','E','E','E',
        'I','I','I','I',
        'O','O','O','O','O',
        'U','U','U','U',
        'C','N'
    ];
    // str_replace() swaps every occurrence of each search string
    // with the corresponding replacement string.
    return str_replace($search, $replace, $str);
}

// ── PREPARE DISPLAY VALUES ───────────────────────────────────

// Map the French payment mode stored in the DB to English labels.
$payment_labels = [
    'especes'  => 'Cash',
    'carte'    => 'Card',
    'virement' => 'Bank Transfer'
];

// Format the sale date from MySQL format (YYYY-MM-DD HH:MM:SS) to MM/DD/YYYY HH:MM.
$date_formatee = date('m/d/Y H:i', strtotime($vente['date_vente']));

// Apply accent stripping to all text that will appear in the PDF.
// If a value is empty or NULL (e.g. anonymous sale), use a fallback string.
$client_nom    = $vente['client_nom']   ? strip_accents($vente['client_nom'])   : 'Anonymous';
$client_tel    = $vente['client_tel']   ? $vente['client_tel']                  : 'N/A';
$client_email  = $vente['client_email'] ? $vente['client_email']                : 'N/A';
$agent_nom     = $vente['agent_nom']    ? strip_accents($vente['agent_nom'])    : 'N/A';

// Pick the English payment label, or fall back to capitalizing the raw value.
$mode_paiement = isset($payment_labels[$vente['mode_paiement']])
    ? $payment_labels[$vente['mode_paiement']]
    : ucfirst($vente['mode_paiement']);

// Zero-padded invoice number string (e.g. sale ID 5 becomes "000005").
$invoice_num = '#' . str_pad($id_vente, 6, '0', STR_PAD_LEFT);

// ============================================================
//  CUSTOM PDF CLASS
//  We extend the base FPDF class to override Header() and Footer().
//  Header() is called automatically by FPDF every time AddPage() runs.
//  Footer() is called automatically when Close() or Output() is called.
// ============================================================
class FactureMVSTOCK extends FPDF {

    // Public property used to pass the sale ID into the Header() method.
    // PHP class methods cannot receive arguments directly, so we use a property.
    public $id_vente = 0;

    // ── HEADER (called automatically at the top of every page) ──
    function Header() {

        // Draw a solid black rectangle spanning the full page width
        // from the very top (y=0) down to y=40. This is the dark band.
        // 'F' means "fill only" (no border drawn separately).
        $this->SetFillColor(26, 26, 26);   // Near-black
        $this->Rect(0, 0, 210, 40, 'F');

        // ── "MVSTOCK" title (left side of the header band) ─────
        $this->SetFont('Helvetica', 'B', 26); // Large bold font
        $this->SetTextColor(201, 162, 39);    // MVSTOCK gold color
        $this->SetXY(12, 7);                  // Position: 12mm from left, 7mm from top
        // Cell(width, height, text, border, move, align)
        // 0 border = no border drawn; 0 after = cursor stays on same line
        $this->Cell(100, 12, 'MVSTOCK', 0, 0, 'L');

        // ── Slogan text below the MVSTOCK title ────────────────
        $this->SetFont('Helvetica', 'I', 8);  // Small italic
        $this->SetTextColor(170, 170, 170);   // Light gray
        $this->SetXY(12, 21);
        $this->Cell(100, 5, 'Sell fast, restock faster', 0, 0, 'L');

        // ── "INVOICE" label (right side of the header band) ────
        $this->SetFont('Helvetica', 'B', 11);
        $this->SetTextColor(201, 162, 39);    // Gold
        $this->SetXY(120, 9);
        // 'R' alignment pushes the text to the right edge of the cell
        $this->Cell(78, 6, 'INVOICE', 0, 2, 'R');

        // ── Invoice number below the "INVOICE" label ───────────
        $this->SetFont('Helvetica', '', 9);
        $this->SetTextColor(200, 200, 200);   // Light gray
        $this->SetX(120);
        // str_pad() zero-pads the number: 5 becomes "000005"
        $this->Cell(78, 5, 'No. ' . str_pad($this->id_vente, 6, '0', STR_PAD_LEFT), 0, 0, 'R');

        // ── Gold horizontal line below the header band ─────────
        // This line visually separates the header from the content.
        $this->SetDrawColor(201, 162, 39);    // Gold line color
        $this->SetLineWidth(0.7);             // Slightly thick line
        $this->Line(0, 40, 210, 40);          // Full-width line at y=40

        // Move the cursor down below the header so content starts below it.
        $this->Ln(12);
    }

    // ── FOOTER (called automatically when the PDF is finalized) ─
    function Footer() {

        // SetY(-20) positions the cursor 20mm from the BOTTOM of the page.
        // Negative values count from the bottom in FPDF.
        $this->SetY(-20);

        // ── Thin gold separator line above the footer text ─────
        $this->SetDrawColor(201, 162, 39);
        $this->SetLineWidth(0.3);
        // GetY() returns the current vertical position after SetY(-20).
        $this->Line(10, $this->GetY(), 200, $this->GetY());
        $this->Ln(2); // Small gap below the line

        // ── Footer text: two cells side by side ────────────────
        $this->SetFont('Helvetica', 'I', 7);
        $this->SetTextColor(150, 150, 150);

        // Left cell: company tagline.
        // NOTE: We use a plain hyphen-minus " - " instead of an em dash "—"
        // because the em dash (Unicode U+2014) is NOT in FPDF's built-in
        // Latin-1 font character set and would print as a garbled character.
        $this->Cell(95, 5, 'MVSTOCK - Sell fast, restock faster', 0, 0, 'L');

        // Right cell: page number — right-aligned.
        // PageNo() returns the current page number as an integer.
        $this->Cell(95, 5, 'Page ' . $this->PageNo(), 0, 0, 'R');
    }
}

// ============================================================
//  INSTANTIATE AND CONFIGURE THE PDF
// ============================================================

// Create an instance of our custom class.
$pdf = new FactureMVSTOCK();

// Pass the sale ID to the class so Header() can display the invoice number.
$pdf->id_vente = $id_vente;

// SetAutoPageBreak(false) disables automatic page breaks.
// This forces everything onto a single page — if there are many products,
// content beyond the page boundary is simply clipped (not continued on page 2).
// We use false here because invoices must be exactly one page.
$pdf->SetAutoPageBreak(false);

// Add the first (and only) page. This automatically calls Header().
$pdf->AddPage();

// ============================================================
//  SECTION 1: MERGED INFO BLOCK
//  One unified full-width table containing both sale information
//  (left half) and customer information (right half).
//  A shared header row spans the full width.
//  A thin vertical divider separates the two sections visually.
// ============================================================

// Total width available between margins (10mm left, 10mm right on 210mm page).
$full_w  = 190; // mm

// The left half holds sale info; the right half holds customer info.
// We split 190mm unevenly: left gets a bit more for longer values.
$left_w  = 95;  // mm — sale information column block
$right_w = 95;  // mm — customer information column block

// Within each half, the label cell is narrow and the value cell fills the rest.
$left_label_w  = 32; // mm — e.g. "Sale No."
$left_value_w  = $left_w - $left_label_w;  // 63mm

$right_label_w = 28; // mm — e.g. "Name"
$right_value_w = $right_w - $right_label_w; // 67mm

// Row height for data rows (in mm).
$row_h = 6;

// ── SHARED HEADER ROW ────────────────────────────────────────
// One dark header cell spans the full width, titled "INVOICE DETAILS".
$pdf->SetX(10);
$pdf->SetFont('Helvetica', 'B', 9);
$pdf->SetFillColor(26, 26, 26);     // Near-black background
$pdf->SetTextColor(201, 162, 39);   // Gold text
// 'LTRB' draws all four borders. The last param 'true' enables fill color.
$pdf->Cell($full_w, 7, '  INVOICE DETAILS', 'LTRB', 1, 'L', true);

// ── SUB-HEADERS ROW ─────────────────────────────────────────
// Below the shared header, two side-by-side sub-headers distinguish
// the sale info section from the customer section.
$pdf->SetX(10);
$pdf->SetFillColor(40, 40, 40);     // Slightly lighter dark gray
$pdf->SetTextColor(201, 162, 39);   // Gold
$pdf->SetFont('Helvetica', 'B', 7);

// Left sub-header: "SALE INFORMATION"
// 'LRB' = draw left, right, and bottom borders (top shared with header above)
$pdf->Cell($left_w, 5, '  SALE INFORMATION', 'LRB', 0, 'L', true);

// Right sub-header: "CUSTOMER" — separated from left by the 'L' border on this cell
$pdf->Cell($right_w, 5, '  CUSTOMER', 'LRB', 1, 'L', true);

// ── DATA ROWS ────────────────────────────────────────────────
// We combine the sale info and customer data into parallel arrays
// so we can draw both sides row by row in a single loop.

// Left side: 4 rows of sale information
$left_data = [
    ['Sale No.',  $invoice_num      ],
    ['Date',      $date_formatee    ],
    ['Payment',   $mode_paiement    ],
    ['Agent',     $agent_nom        ],
];

// Right side: 3 rows of customer data + 1 blank to match height
$right_data = [
    ['Name',    $client_nom  ],
    ['Phone',   $client_tel  ],
    ['Email',   $client_email],
    ['',        ''           ],  // Empty row to align with the 4th left row
];

// Determine how many rows to draw (the longer of the two sides).
$num_rows = max(count($left_data), count($right_data));

// Loop through each data row and draw both left and right cells side by side.
for ($i = 0; $i < $num_rows; $i++) {

    // Alternate row background: slightly off-white vs pure white for readability.
    $bg = ($i % 2 === 0) ? 248 : 255; // 248 = very light gray, 255 = pure white
    $pdf->SetFillColor($bg, $bg, $bg);

    // Reposition cursor to the left margin for each row.
    $pdf->SetX(10);

    // ── LEFT SIDE: sale info label ──────────────────────────
    // 'LR' border = left and right sides only (rows share top/bottom via adjacency)
    $pdf->SetFont('Helvetica', 'B', 8);
    $pdf->SetTextColor(80, 80, 80);   // Dark gray for labels
    $label = isset($left_data[$i]) ? '  ' . $left_data[$i][0] : '';
    $pdf->Cell($left_label_w, $row_h, $label, 'LR', 0, 'L', true);

    // ── LEFT SIDE: sale info value ──────────────────────────
    $pdf->SetFont('Helvetica', '', 8);
    $pdf->SetTextColor(26, 26, 26);   // Near-black for values
    $value = isset($left_data[$i]) ? $left_data[$i][1] : '';
    $pdf->Cell($left_value_w, $row_h, $value, 'LR', 0, 'L', true);

    // ── RIGHT SIDE: customer label ──────────────────────────
    $pdf->SetFont('Helvetica', 'B', 8);
    $pdf->SetTextColor(80, 80, 80);
    $label_r = isset($right_data[$i]) ? '  ' . $right_data[$i][0] : '';
    $pdf->Cell($right_label_w, $row_h, $label_r, 'LR', 0, 'L', true);

    // ── RIGHT SIDE: customer value ──────────────────────────
    $pdf->SetFont('Helvetica', '', 8);
    $pdf->SetTextColor(26, 26, 26);
    $value_r = isset($right_data[$i]) ? $right_data[$i][1] : '';
    $pdf->Cell($right_value_w, $row_h, $value_r, 'LR', 1, 'L', true);
}

// ── CLOSING BORDER LINE at the bottom of the merged info block ──
// Drawing a cell with only a 'T' (top) border acts as a bottom border
// for the last data row, completing the table visually.
$pdf->SetX(10);
$pdf->Cell($full_w, 0, '', 'T');
$pdf->Ln(6); // Small gap before the product table

// ============================================================
//  SECTION 2: PRODUCT TABLE
//  Lists every product in the sale with quantity, unit price,
//  and subtotal. The TOTAL block is attached directly as the
//  last rows of this same table (no floating gap between them).
// ============================================================

// Column widths within the product table (must sum to $full_w = 190mm).
$col_product   = 90; // Product name — widest column
$col_qty       = 24; // Quantity
$col_unitprice = 38; // Unit price
$col_subtotal  = 38; // Line subtotal (quantity x unit price)
// Total check: 90 + 24 + 38 + 38 = 190 ✓

// ── PRODUCT TABLE HEADER ROW ────────────────────────────────
$pdf->SetFont('Helvetica', 'B', 9);
$pdf->SetFillColor(26, 26, 26);     // Dark header background
$pdf->SetTextColor(201, 162, 39);   // Gold header text
$pdf->SetDrawColor(51, 51, 51);     // Dark border color
$pdf->SetLineWidth(0.3);

$pdf->SetX(10);
// Each header cell draws all 4 borders ('TLRB') and fills with dark background.
$pdf->Cell($col_product,   8, '  Product',    'TLRB', 0, 'L', true);
$pdf->Cell($col_qty,       8, 'Qty',          'TLRB', 0, 'C', true);
$pdf->Cell($col_unitprice, 8, 'Unit Price',   'TLRB', 0, 'C', true);
$pdf->Cell($col_subtotal,  8, 'Subtotal',     'TLRB', 1, 'C', true);

// ── PRODUCT DATA ROWS ────────────────────────────────────────
$pdf->SetFont('Helvetica', '', 9);
$pdf->SetTextColor(26, 26, 26);     // Dark text for data rows
$pdf->SetDrawColor(204, 204, 204);  // Light gray borders between rows

foreach ($lignes as $i => $ligne) {

    // Alternate row background for visual separation.
    $fill = ($i % 2 === 0);
    // White rows (255) and very light gray rows (245) alternate.
    $pdf->SetFillColor($fill ? 255 : 245, $fill ? 255 : 245, $fill ? 255 : 245);

    $pdf->SetX(10);
    // 'LR' borders draw the left and right edges of each row.
    // The top/bottom borders are implied by the adjacent rows above/below.
    $pdf->Cell($col_product,   7, '  ' . strip_accents($ligne['designation']),         'LR', 0, 'L', true);
    $pdf->Cell($col_qty,       7, $ligne['quantite'],                                  'LR', 0, 'C', true);
    // number_format() formats to 2 decimal places: "13.49" → "13.49"
    $pdf->Cell($col_unitprice, 7, '$ ' . number_format($ligne['prix_unitaire'], 2),    'LR', 0, 'R', true);
    $pdf->Cell($col_subtotal,  7, '$ ' . number_format($ligne['sous_total'],    2),    'LR', 1, 'R', true);
}

// ── TOTAL ROWS (attached directly to the product table) ──────
// These rows continue seamlessly from the product rows above —
// no gap, no separate block. They are visually part of the same table.

// Thin separator line above the total section.
$pdf->SetX(10);
$pdf->Cell($full_w, 0, '', 'T');  // Top-border-only cell = horizontal line

// ── SUBTOTAL ROW ─────────────────────────────────────────────
// Right-aligned: empty wide cell on the left, label+value on the right.
$pdf->SetFont('Helvetica', '', 9);
$pdf->SetFillColor(245, 245, 245);
$pdf->SetTextColor(80, 80, 80);
$pdf->SetX(10);
// Large empty left cell spans product + qty columns.
$pdf->Cell($col_product + $col_qty, 7, '', 'LR', 0, 'L', true);
// Label cell
$pdf->Cell($col_unitprice, 7, 'Subtotal', 'LR', 0, 'R', true);
// Value cell
$pdf->Cell($col_subtotal, 7, '$ ' . number_format($vente['montant_total'], 2), 'LR', 1, 'R', true);

// ── TAX ROW ──────────────────────────────────────────────────
$pdf->SetX(10);
$pdf->Cell($col_product + $col_qty, 7, '', 'LR', 0, 'L', true);
$pdf->Cell($col_unitprice, 7, 'Tax (0%)', 'LR', 0, 'R', true);
$pdf->Cell($col_subtotal, 7, '$ 0.00', 'LR', 1, 'R', true);

// ── GRAND TOTAL ROW (dark background, gold text) ─────────────
$pdf->SetFont('Helvetica', 'B', 10);
$pdf->SetFillColor(26, 26, 26);     // Black background matching header
$pdf->SetTextColor(201, 162, 39);   // Gold text — most visually prominent element
$pdf->SetX(10);
// Large empty left cell
$pdf->Cell($col_product + $col_qty, 9, '', 'TLRB', 0, 'L', true);
// "TOTAL" label
$pdf->Cell($col_unitprice, 9, 'TOTAL', 'TLRB', 0, 'R', true);
// Total value
$pdf->Cell($col_subtotal, 9, '$ ' . number_format($vente['montant_total'], 2), 'TLRB', 1, 'R', true);

// ============================================================
//  SECTION 3: THANK-YOU MESSAGE
//  Small centered italic note below the product table.
// ============================================================
$pdf->Ln(8); // Gap below the product table
$pdf->SetFont('Helvetica', 'I', 8);
$pdf->SetTextColor(140, 140, 140); // Light gray — subtle, not distracting
$pdf->SetX(10);
// This note uses only ASCII characters to avoid encoding issues.
$pdf->Cell($full_w, 6, 'Thank you for your business. For any questions, please contact your MVSTOCK agent.', 0, 1, 'C');

// ============================================================
//  SECTION 4: DECORATIVE BOTTOM BAND
//  A narrow black band near the bottom of the page, above the footer.
//  This reinforces the MVSTOCK branding at the bottom of the invoice.
// ============================================================
// Position the band 32mm from the bottom of the page (footer is at -20mm,
// so we leave space for it). GetPageHeight() returns 297 for A4.
$pdf->SetY($pdf->GetPageHeight() - 32);
$pdf->SetFillColor(26, 26, 26);
// Rect(x, y, width, height, style) — 'F' = fill rectangle
$pdf->Rect(0, $pdf->GetY(), 210, 12, 'F');
$pdf->SetFont('Helvetica', 'B', 8);
$pdf->SetTextColor(201, 162, 39);
$pdf->SetX(10);
// NOTE: We use " | " as a separator here instead of "—" (em dash).
// The em dash (Unicode U+2014) is not in FPDF's built-in Latin-1 font
// charset, so it would render as a garbled or missing character.
$pdf->Cell($full_w, 12, 'MVSTOCK  |  Sell fast, restock faster  |  Cleanify Lebanon', 0, 0, 'C');

// ============================================================
//  OUTPUT: SEND PDF TO THE BROWSER AS A DOWNLOAD
//  'D' tells FPDF to force a file download dialog.
//  The filename includes the zero-padded invoice number.
// ============================================================
$nom_fichier = 'Invoice_MVSTOCK_' . str_pad($id_vente, 6, '0', STR_PAD_LEFT) . '.pdf';
$pdf->Output('D', $nom_fichier);
?>
