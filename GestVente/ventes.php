<?php
/*
 * ============================================================
 * FILE: ventes.php — MODULE VENTES (100 % PHP, sans JavaScript)
 *
 * Ce fichier gère tout le cycle de vie d'une vente :
 *   1. Afficher un formulaire avec 5 lignes de produits fixes
 *   2. Valider les stocks côté serveur sur chaque ligne
 *   3. Ré-afficher le formulaire avec les valeurs conservées
 *      et les lignes problématiques surlignées en rouge
 *   4. Enregistrer la vente, déduire les stocks, attribuer
 *      des points de fidélité
 *   5. Lister les 20 dernières ventes
 *   6. Afficher le détail d'une vente (?voir=ID)
 *   7. Générer la facture PDF (lien vers facture.php)
 *
 * STRATÉGIE DE VALIDATION DES STOCKS (PHP uniquement)
 * ---------------------------------------------------
 * Quand l'agent clique "Enregistrer", le navigateur envoie
 * le formulaire au serveur PHP. PHP vérifie TOUTES les lignes
 * d'un seul coup (pas seulement la première erreur trouvée).
 * Si des lignes dépassent le stock disponible :
 *   - La page se recharge avec le formulaire pré-rempli
 *   - Les lignes problématiques ont la classe CSS "row-error"
 *     → bordure rouge + animation de pulsation (CSS pur)
 *   - Les autres lignes restent intactes
 * La vente n'est enregistrée que quand toutes les lignes
 * passent la validation.
 * ============================================================
 */

// Démarre (ou reprend) la session PHP pour lire $_SESSION.
session_start();

// Inclut config.php qui crée la variable $conn (connexion MySQL).
require_once 'config.php';

// GARDE DE SÉCURITÉ : redirige vers la page de connexion si non connecté.
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

// Variables de retour affichées à l'agent après une action.
// $message → barre verte de succès.
// $erreur  → barre rouge d'erreur.
$message = "";
$erreur  = "";

// Nombre de lignes produit fixes dans le formulaire.
// Modifiez cette constante pour ajouter ou supprimer des lignes.
define('NB_ROWS', 5);

/*
 * $stock_errors : tableau associatif [ index_ligne => message_erreur ]
 * pour les lignes qui ont échoué la vérification des stocks.
 *
 * Exemple : [ 2 => "Stock insuffisant (disponible : 3)" ]
 *
 * PHP utilise ce tableau lors du re-rendu du formulaire pour
 * ajouter la classe CSS "row-error" aux lignes concernées.
 */
$stock_errors = [];

/*
 * ──────────────────────────────────────────────────────────────
 * MÉMOIRE DU FORMULAIRE
 * ──────────────────────────────────────────────────────────────
 * Ces variables conservent ce que l'agent a saisi.
 * À la première ouverture de la page, elles contiennent les
 * valeurs par défaut (formulaire vide).
 * Après un POST échoué, elles sont remplies avec les données
 * soumises pour que l'agent ne perde pas son travail.
 */
$form_client    = "";           // id_client soumis (vide = vente anonyme)
$form_paiement  = "especes";    // mode_paiement soumis

// Tableaux de NB_ROWS éléments — un par ligne produit.
// array_fill(debut, nombre, valeur_initiale)
$form_produits  = array_fill(0, NB_ROWS, 0); // ID produit sélectionné (0 = aucun)
$form_quantites = array_fill(0, NB_ROWS, 1); // Quantité saisie (défaut : 1)

/*
 * ══════════════════════════════════════════════════════════════
 * TRAITEMENT D'UNE NOUVELLE VENTE  (POST, action = 'vendre')
 * ══════════════════════════════════════════════════════════════
 */
if ($_SERVER['REQUEST_METHOD'] == 'POST' &&
    isset($_POST['action']) && $_POST['action'] == 'vendre') {

    /*
     * ── Étape 0 : Capturer les valeurs soumises ────────────────
     * On enregistre ce que l'agent a tapé AVANT la validation,
     * pour pouvoir ré-afficher le formulaire intact en cas d'erreur.
     */
    $form_client   = !empty($_POST['id_client']) ? (int) $_POST['id_client'] : "";
    $form_paiement = mysqli_real_escape_string($conn, $_POST['mode_paiement']);

    // Ré-alimenter les tableaux de lignes depuis le POST.
    if (isset($_POST['id_produit']) && is_array($_POST['id_produit'])) {
        foreach ($_POST['id_produit'] as $i => $v) {
            if (array_key_exists($i, $form_produits)) {
                $form_produits[$i] = (int) $v;
            }
        }
    }
    if (isset($_POST['quantite']) && is_array($_POST['quantite'])) {
        foreach ($_POST['quantite'] as $i => $v) {
            if (array_key_exists($i, $form_quantites)) {
                $form_quantites[$i] = (int) $v;
            }
        }
    }

    /*
     * ── Étape 1 : Préparer les variables de la vente ──────────
     */
    // ID de l'agent connecté — cast en entier pour sécuriser la requête SQL.
    $id_utilisateur = (int) $_SESSION['user_id'];

    // ID du client : entier ou chaîne "NULL" pour une vente anonyme.
    // La chaîne "NULL" est insérée telle quelle dans le SQL et interprétée
    // comme la valeur NULL de SQL (pas la chaîne "NULL").
    $id_client = !empty($_POST['id_client'])
        ? (int) $_POST['id_client']
        : "NULL";

    // mysqli_real_escape_string() échappe les caractères dangereux
    // dans la chaîne pour éviter les injections SQL.
    $mode_paiement = mysqli_real_escape_string($conn, $_POST['mode_paiement']);

    // Contiendra les lignes qui ont passé TOUTES les validations.
    $lignes_valides = [];

    /*
     * ── Étape 2 : Valider chaque ligne produit ─────────────────
     * On parcourt toutes les lignes soumises. Au lieu de s'arrêter
     * à la première erreur, on continue jusqu'au bout pour signaler
     * TOUS les problèmes en une seule passe.
     */
    if (isset($_POST['id_produit']) && is_array($_POST['id_produit'])) {

        foreach ($_POST['id_produit'] as $index => $id_produit) {

            $id_produit = (int) $id_produit;
            $quantite   = isset($_POST['quantite'][$index])
                ? (int) $_POST['quantite'][$index]
                : 0;

            // Ignorer les lignes vides (aucun produit sélectionné ou quantité invalide).
            if ($id_produit == 0 || $quantite <= 0) {
                continue;
            }

            /*
             * Récupérer le prix et le stock DEPUIS LA BASE DE DONNÉES.
             * On ne fait jamais confiance au navigateur pour le prix —
             * n'importe qui peut modifier une valeur dans le navigateur.
             * En relisant depuis la base, on est sûr d'avoir le bon prix.
             */
            $res     = mysqli_query($conn,
                "SELECT designation, prix_unitaire, stock_actuel
                 FROM produit WHERE id_produit = $id_produit"
            );
            $produit = mysqli_fetch_assoc($res);

            // ── VÉRIFICATION DU STOCK ──────────────────────────────
            // Si la quantité demandée dépasse le stock disponible,
            // on enregistre l'erreur et on passe à la ligne suivante
            // (on ne s'arrête PAS — on vérifie toutes les lignes).
            if ($produit['stock_actuel'] < $quantite) {
                $stock_errors[$index] =
                    "Stock insuffisant (disponible&nbsp;: " . $produit['stock_actuel'] . ")";
                continue; // Passer à la ligne suivante
            }

            // La ligne est valide — l'ajouter à la liste.
            $lignes_valides[] = [
                'id_produit'    => $id_produit,
                'quantite'      => $quantite,
                'prix_unitaire' => (float) $produit['prix_unitaire'],
                'designation'   => $produit['designation'],
            ];
        }
    }

    /*
     * ── Étape 3 : Agir selon les résultats de validation ──────
     */
    if (!empty($stock_errors)) {
        // Au moins une ligne dépasse le stock.
        // Le formulaire sera ré-affiché avec les lignes problématiques en rouge.
        $erreur = "Certains produits d&eacute;passent le stock disponible. "
                . "Veuillez corriger les lignes surlign&eacute;es en rouge.";

    } elseif (count($lignes_valides) === 0) {
        // Toutes les lignes étaient vides — aucun produit sélectionné.
        $erreur = "Veuillez s&eacute;lectionner au moins un produit.";

    } else {
        /*
         * ── TOUTES LES LIGNES SONT VALIDES — ENREGISTRER LA VENTE ─
         */

        // ÉTAPE A : Calculer le montant total de la commande.
        $montant_total = 0;
        foreach ($lignes_valides as $ligne) {
            $montant_total += $ligne['prix_unitaire'] * $ligne['quantite'];
        }

        // ÉTAPE B : Insérer l'en-tête de la vente dans la table "vente".
        // L'id_vente est généré automatiquement par MySQL (AUTO_INCREMENT).
        $sql_vente =
            "INSERT INTO vente (montant_total, mode_paiement, id_client, id_utilisateur)
             VALUES ($montant_total, '$mode_paiement', $id_client, $id_utilisateur)";

        if (mysqli_query($conn, $sql_vente)) {

            // mysqli_insert_id() renvoie l'ID de la ligne qu'on vient d'insérer.
            // On en a besoin pour lier les lignes produit à cette vente.
            $id_vente = mysqli_insert_id($conn);

            foreach ($lignes_valides as $ligne) {

                // ÉTAPE C : Insérer chaque ligne produit dans "ligne_vente".
                // On stocke le prix_unitaire ici pour conserver l'historique :
                // si le prix change demain, les anciennes factures restent justes.
                mysqli_query($conn,
                    "INSERT INTO ligne_vente
                         (quantite, prix_unitaire, id_vente, id_produit)
                     VALUES
                         ({$ligne['quantite']}, {$ligne['prix_unitaire']},
                          $id_vente, {$ligne['id_produit']})"
                );

                // ÉTAPE D : Déduire la quantité vendue du stock du produit.
                mysqli_query($conn,
                    "UPDATE produit
                     SET stock_actuel = stock_actuel - {$ligne['quantite']}
                     WHERE id_produit = {$ligne['id_produit']}"
                );
            }

            // ÉTAPE E : Attribuer des points de fidélité au client.
            // Règle : 1 point par unité monétaire dépensée.
            // On ne fait rien pour les ventes anonymes ($id_client = "NULL").
            if ($id_client != "NULL") {
                $points = (int) $montant_total;
                mysqli_query($conn,
                    "UPDATE client
                     SET points_fidelite = points_fidelite + $points
                     WHERE id_client = $id_client"
                );
            }

            // Vente enregistrée : réinitialiser le formulaire à vide.
            $form_client    = "";
            $form_paiement  = "especes";
            $form_produits  = array_fill(0, NB_ROWS, 0);
            $form_quantites = array_fill(0, NB_ROWS, 1);

            $message = "Vente #$id_vente enregistr&eacute;e&nbsp;! Total&nbsp;: $"
                     . number_format($montant_total, 2);

        } else {
            $erreur = "Erreur lors de l'enregistrement&nbsp;: " . mysqli_error($conn);
        }
    }
}

/*
 * ══════════════════════════════════════════════════════════════
 * CHARGER LES DONNÉES POUR LES MENUS DÉROULANTS
 * ══════════════════════════════════════════════════════════════
 */

// Tous les clients par ordre alphabétique pour le sélecteur.
$clients = mysqli_query($conn,
    "SELECT id_client, nom, prenom FROM client ORDER BY nom"
);

// Tous les produits avec leur stock — pour les listes déroulantes de chaque ligne.
$produits_res   = mysqli_query($conn,
    "SELECT id_produit, designation, prix_unitaire, stock_actuel
     FROM produit ORDER BY designation"
);
$produits_array = [];
while ($p = mysqli_fetch_assoc($produits_res)) {
    $produits_array[] = $p;
}

// 20 dernières ventes pour le tableau en bas de page.
$ventes = mysqli_query($conn,
    "SELECT v.id_vente, v.date_vente, v.montant_total, v.mode_paiement,
            CONCAT(c.prenom, ' ', c.nom) AS client_nom,
            CONCAT(u.prenom, ' ', u.nom) AS agent_nom
     FROM vente v
     LEFT JOIN client      c ON v.id_client      = c.id_client
     LEFT JOIN utilisateur u ON v.id_utilisateur = u.id_utilisateur
     ORDER BY v.date_vente DESC
     LIMIT 20"
);

// Vue détail d'une vente : déclenchée par ?voir=ID dans l'URL.
$detail_vente  = null;
$detail_lignes = null;
if (isset($_GET['voir'])) {
    $id = (int) $_GET['voir'];
    $res_detail = mysqli_query($conn,
        "SELECT v.*,
                CONCAT(c.prenom, ' ', c.nom) AS client_nom,
                CONCAT(u.prenom, ' ', u.nom) AS agent_nom
         FROM vente v
         LEFT JOIN client      c ON v.id_client      = c.id_client
         LEFT JOIN utilisateur u ON v.id_utilisateur = u.id_utilisateur
         WHERE v.id_vente = $id"
    );
    $detail_vente  = mysqli_fetch_assoc($res_detail);
    $detail_lignes = mysqli_query($conn,
        "SELECT p.designation, lv.quantite, lv.prix_unitaire,
                (lv.quantite * lv.prix_unitaire) AS sous_total
         FROM ligne_vente lv
         JOIN produit p ON lv.id_produit = p.id_produit
         WHERE lv.id_vente = $id"
    );
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Ventes &ndash; MVSTOCK</title>
    <link rel="stylesheet" href="style.css">

    <style>
        /* ============================================================
           STYLES PROPRES À LA PAGE VENTES
           Ces règles complètent style.css pour les éléments
           spécifiques au formulaire de vente.
           ============================================================ */

        /* ── Ligne produit (conteneur d'une ligne du formulaire) ── */
        .product-row {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 10px;
            padding: 14px 16px;
            background: #111;
            border: 1px solid #2A2A2A;
            border-radius: 4px;
            /* Transition douce pour que la bordure rouge apparaisse
               progressivement quand PHP ajoute la classe row-error. */
            transition: border-color 0.25s, box-shadow 0.25s;
            position: relative; /* Nécessaire pour positionner le message d'erreur */
        }

        /* Bordure dorée quand un champ de la ligne reçoit le focus clavier. */
        .product-row:focus-within {
            border-color: #D4AF37;
        }

        /* ── ÉTAT ERREUR DE STOCK ─────────────────────────────────
         * Ajouté par PHP (pas par JavaScript) quand une ligne
         * dépasse le stock disponible.
         * La bordure rouge et l'animation attirent l'œil de l'agent
         * exactement sur la ligne à corriger — les autres restent intactes.
         *
         * !important est nécessaire ici pour surpasser la règle
         * :focus-within définie juste au-dessus.
         */
        .product-row.row-error {
            border-color: #EF4444 !important; /* Bordure rouge vif */
            /* box-shadow crée l'effet de "halo" lumineux :
               - Premier ombre : anneau rouge serré (3px de diffusion)
               - Deuxième ombre : halo plus large et doux (12px)
               L'animation fait pulser les deux intensités en alternance. */
            box-shadow:
                0 0 0 3px rgba(239, 68, 68, 0.20),
                0 0 12px rgba(239, 68, 68, 0.15);
            animation: pulse-red 1.6s ease-in-out infinite;
            background: #1A0808; /* Fond légèrement teinté de rouge */
            padding-bottom: 28px; /* Espace pour le message d'erreur en dessous */
        }

        /* Animation CSS : le halo rouge pulse entre deux niveaux d'intensité. */
        @keyframes pulse-red {
            0%, 100% {
                box-shadow:
                    0 0 0 3px rgba(239, 68, 68, 0.20),
                    0 0 12px rgba(239, 68, 68, 0.12);
            }
            50% {
                /* Le halo devient plus intense au milieu de chaque cycle. */
                box-shadow:
                    0 0 0 4px rgba(239, 68, 68, 0.38),
                    0 0 22px rgba(239, 68, 68, 0.28);
            }
        }

        /* Message d'erreur affiché à l'intérieur de la ligne en rouge.
           Rendu par PHP avec le message exact (ex. "Stock insuffisant (disponible : 3)"). */
        .stock-error-msg {
            position: absolute; /* Positionné par rapport à .product-row */
            bottom: 5px;        /* Près du bord inférieur de la ligne */
            left: 50px;         /* Décalé après le numéro de ligne */
            font-size: 0.7rem;
            font-weight: 700;
            color: #F87171;     /* Rouge clair assorti à la bordure */
            letter-spacing: 0.3px;
        }

        /* ── Numéro de ligne (ex. "1", "2"…) ─────────────────── */
        .row-number {
            color: #555;
            font-size: 0.8rem;
            font-weight: 700;
            min-width: 20px;
            text-align: center;
        }

        /* ── Menu déroulant produit ───────────────────────────── */
        .product-row select {
            flex: 2;
            padding: 9px 12px;
            background: #0D0D0D;
            color: #E0E0E0;
            border: 1px solid #333;
            border-radius: 3px;
            font-size: 0.88rem;
            outline: none;
            transition: border-color 0.15s;
        }
        .product-row select:focus  { border-color: #D4AF37; }
        .product-row select option { background: #171717; color: #E0E0E0; }

        /* ── Champ quantité ───────────────────────────────────── */
        .product-row input[type="number"] {
            flex: 0 0 80px;
            padding: 9px 10px;
            background: #0D0D0D;
            color: #E0E0E0;
            border: 1px solid #333;
            border-radius: 3px;
            font-size: 0.88rem;
            text-align: center;
            outline: none;
            transition: border-color 0.15s;
        }
        .product-row input[type="number"]:focus { border-color: #D4AF37; }

        /* ── En-têtes de colonnes au-dessus des lignes ───────── */
        .product-row-headers {
            display: flex;
            gap: 12px;
            padding: 0 16px;
            margin-bottom: 6px;
        }
        .product-row-headers span {
            font-size: 0.65rem;
            font-weight: 700;
            color: #555;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        /* ── Barre d'action (bouton Enregistrer) ─────────────── */
        .form-actions {
            display: flex;
            justify-content: flex-end; /* Bouton aligné à droite */
            margin-top: 20px;
            padding-top: 16px;
            border-top: 1px solid #1E1E1E;
        }
    </style>
</head>
<body>
<div class="dashboard-layout">

    <!-- ===== BARRE LATÉRALE ===== -->
    <aside class="sidebar">
        <div class="sidebar-logo">
            <h1>MVSTOCK</h1>
            <p>Sell fast, restock faster.</p>
        </div>
        <nav>
            <a href="accueil.php"><span class="icon">&#x1F3E0;</span> Tableau de bord</a>
            <a href="produits.php"><span class="icon">&#x1F4E6;</span> Produits</a>
            <a href="clients.php"><span class="icon">&#x1F465;</span> Clients</a>
            <a href="ventes.php" class="active"><span class="icon">&#x1F6D2;</span> Ventes</a>
            <?php if ($_SESSION['user_role'] == 'admin'): ?>
            <a href="stats.php"><span class="icon">&#x1F4CA;</span> Statistiques</a>
            <?php endif; ?>
            <?php if ($_SESSION['user_role'] == 'admin'): ?>
            <a href="utilisateurs.php"><span class="icon">&#x1F464;</span> Utilisateurs</a>
            <?php endif; ?>
        </nav>
        <div class="sidebar-footer">
            <a href="deconnexion.php"><span>&#x1F6AA;</span> D&eacute;connexion</a>
        </div>
    </aside>

    <!-- ===== CONTENU PRINCIPAL ===== -->
    <main class="main-content">

        <div class="topbar">
            <h2>&#x1F6D2; Ventes</h2>
            <div class="user-info">
                <span>&#x1F44B; <?php echo htmlspecialchars($_SESSION['user_nom']); ?></span>
                <span class="badge-role"><?php echo $_SESSION['user_role']; ?></span>
            </div>
        </div>

        <!-- Message de succès (vert) — affiché après une vente enregistrée -->
        <?php if ($message != ""): ?>
            <div class="alert-msg success"><?php echo $message; ?></div>
        <?php endif; ?>

        <!-- Message d'erreur (rouge) — affiché si la validation échoue -->
        <?php if ($erreur != ""): ?>
            <div class="alert-msg danger"><?php echo $erreur; ?></div>
        <?php endif; ?>

        <?php if ($detail_vente): ?>
        <!-- ══════════════════════════════════════════════════════
             VUE DÉTAIL D'UNE VENTE  (?voir=ID)
             ══════════════════════════════════════════════════════ -->
        <div class="card">
            <div class="card-header">
                <h3>&#x1F9FE; D&eacute;tail &ndash; Vente #<?php echo $detail_vente['id_vente']; ?></h3>
                <div style="display:flex; gap:10px;">
                    <a href="ventes.php" class="btn btn-secondary">&larr; Retour</a>
                    <a href="facture.php?id=<?php echo $detail_vente['id_vente']; ?>"
                       class="btn btn-primary"
                       style="background:#C9A227; color:#1A1A1A; font-weight:700; border:none;">
                        &#x2B07;&#xFE0F; T&eacute;l&eacute;charger la facture PDF
                    </a>
                </div>
            </div>
            <div style="padding:24px;">
                <!-- Grille d'informations sur la vente (4 colonnes) -->
                <div style="display:grid; grid-template-columns:1fr 1fr 1fr 1fr; gap:16px; margin-bottom:24px;">
                    <div>
                        <p style="color:#888; font-size:0.8rem;">CLIENT</p>
                        <p style="font-weight:600;">
                            <?php echo $detail_vente['client_nom']
                                ? htmlspecialchars($detail_vente['client_nom'])
                                : 'Anonyme'; ?>
                        </p>
                    </div>
                    <div>
                        <p style="color:#888; font-size:0.8rem;">AGENT</p>
                        <p style="font-weight:600;"><?php echo htmlspecialchars($detail_vente['agent_nom']); ?></p>
                    </div>
                    <div>
                        <p style="color:#888; font-size:0.8rem;">PAIEMENT</p>
                        <p style="font-weight:600;"><?php echo ucfirst($detail_vente['mode_paiement']); ?></p>
                    </div>
                    <div>
                        <p style="color:#888; font-size:0.8rem;">DATE</p>
                        <p style="font-weight:600;">
                            <?php echo date('d/m/Y \&agrave; H:i', strtotime($detail_vente['date_vente'])); ?>
                        </p>
                    </div>
                </div>

                <!-- Tableau des lignes produit de cette vente -->
                <table>
                    <thead>
                        <tr>
                            <th>Produit</th>
                            <th>Prix unitaire</th>
                            <th>Quantit&eacute;</th>
                            <th>Sous-total</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php while ($l = mysqli_fetch_assoc($detail_lignes)): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($l['designation']); ?></td>
                            <td>$<?php echo number_format($l['prix_unitaire'], 2); ?></td>
                            <td><?php echo $l['quantite']; ?></td>
                            <td><strong>$<?php echo number_format($l['sous_total'], 2); ?></strong></td>
                        </tr>
                    <?php endwhile; ?>
                    <!-- Ligne de total en bas du tableau -->
                    <tr style="background:#111;">
                        <td colspan="3"
                            style="text-align:right; font-weight:700; padding:14px 24px; color:#F0F0F0;">
                            TOTAL
                        </td>
                        <td style="font-weight:700; font-size:1.1rem; color:#D4AF37;">
                            $<?php echo number_format($detail_vente['montant_total'], 2); ?>
                        </td>
                    </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <?php else: ?>
        <!-- ══════════════════════════════════════════════════════
             FORMULAIRE NOUVELLE VENTE  +  LISTE DES VENTES
             ══════════════════════════════════════════════════════ -->

        <div class="card" style="margin-bottom:24px;">
            <div class="card-header">
                <h3>&#x2795; Nouvelle vente</h3>
            </div>
            <div style="padding:24px;">

                <!--
                    Formulaire de saisie d'une vente.
                    method="POST" : les données sont envoyées au serveur PHP.
                    action="ventes.php" : la même page reçoit et traite le formulaire.

                    COMMENT FONCTIONNE LE TRAITEMENT PHP :
                    1. L'agent remplit le formulaire et clique "Enregistrer".
                    2. Le navigateur envoie une requête POST à ventes.php.
                    3. PHP lit $_POST, valide les stocks, et soit :
                       a. Enregistre la vente → page rechargée avec message de succès
                       b. Trouve des erreurs  → page rechargée avec le formulaire
                          ré-rempli ET les lignes problématiques en rouge
                -->
                <form method="POST" action="ventes.php">
                    <input type="hidden" name="action" value="vendre">

                    <!-- ── Sélecteur client + mode de paiement ── -->
                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:24px;">

                        <div class="form-group">
                            <label>Client (optionnel &ndash; anonyme si vide)</label>
                            <select name="id_client">
                                <option value="">-- Vente anonyme --</option>
                                <?php while ($c = mysqli_fetch_assoc($clients)): ?>
                                    <!--
                                        selected="selected" est ajouté par PHP sur l'option
                                        qui correspond à ce que l'agent avait choisi.
                                        Ainsi, après un POST échoué, le client reste sélectionné.
                                    -->
                                    <option value="<?php echo $c['id_client']; ?>"
                                        <?php echo ($form_client == $c['id_client']) ? 'selected="selected"' : ''; ?>>
                                        <?php echo htmlspecialchars($c['prenom'] . ' ' . $c['nom']); ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label>Mode de paiement *</label>
                            <select name="mode_paiement">
                                <!--
                                    Pour chaque option, PHP compare $form_paiement à la valeur
                                    de l'option. Si c'est la même, il ajoute selected="selected"
                                    pour ré-sélectionner le mode de paiement après un POST échoué.
                                -->
                                <option value="especes"
                                    <?php echo ($form_paiement == 'especes')  ? 'selected="selected"' : ''; ?>>
                                    &#x1F4B5; Esp&egrave;ces
                                </option>
                                <option value="carte"
                                    <?php echo ($form_paiement == 'carte')    ? 'selected="selected"' : ''; ?>>
                                    &#x1F4B3; Carte
                                </option>
                                <option value="virement"
                                    <?php echo ($form_paiement == 'virement') ? 'selected="selected"' : ''; ?>>
                                    &#x1F3E6; Virement
                                </option>
                            </select>
                        </div>
                    </div>

                    <p style="font-size:0.75rem; font-weight:700; color:#888;
                              text-transform:uppercase; letter-spacing:1px; margin-bottom:12px;">
                        Produits vendus
                    </p>

                    <!-- En-têtes de colonnes au-dessus des lignes produit -->
                    <div class="product-row-headers">
                        <span style="min-width:20px;">#</span>
                        <span style="flex:2;">Produit</span>
                        <span style="flex:0 0 80px; text-align:center;">Qt&eacute;</span>
                    </div>

                    <!--
                        ══════════════════════════════════════════════════════
                        LIGNES PRODUIT FIXES — rendues par une boucle PHP

                        Pourquoi une boucle PHP plutôt que du JavaScript ?
                        PHP tourne sur le SERVEUR et génère le HTML avant
                        que le navigateur ne reçoive la page.
                        PHP peut donc :
                          - Appliquer la classe "row-error" sur les lignes qui
                            ont échoué la validation (tableau $stock_errors)
                          - Ré-sélectionner le produit choisi (tableau $form_produits)
                          - Ré-remplir la quantité saisie (tableau $form_quantites)

                        La variable NB_ROWS contrôle le nombre de lignes.
                        ══════════════════════════════════════════════════════
                    -->
                    <?php for ($i = 0; $i < NB_ROWS; $i++): ?>

                        <!--
                            array_key_exists($i, $stock_errors) vérifie si cette ligne ($i)
                            est dans le tableau des erreurs renvoyé par PHP.
                            Si oui, on ajoute la classe CSS "row-error" qui déclenche
                            le halo rouge et l'animation de pulsation (définis dans le <style>).
                            Sinon, on n'ajoute rien et la ligne reste normale.
                        -->
                        <div class="product-row <?php echo array_key_exists($i, $stock_errors) ? 'row-error' : ''; ?>">

                            <!-- Numéro de ligne affiché à gauche (commence à 1) -->
                            <span class="row-number"><?php echo $i + 1; ?></span>

                            <!--
                                Menu déroulant des produits pour cette ligne.
                                name="id_produit[]" : les crochets [] indiquent à PHP
                                que c'est un tableau — $_POST['id_produit'] sera un
                                tableau avec une valeur par ligne.

                                PHP compare chaque option à $form_produits[$i]
                                pour ré-sélectionner le produit soumis après un POST échoué.
                            -->
                            <select name="id_produit[]" style="flex:2;">
                                <option value="0">-- S&eacute;lectionner un produit --</option>
                                <?php foreach ($produits_array as $p): ?>
                                    <option value="<?php echo $p['id_produit']; ?>"
                                        <?php
                                        // Ré-sélectionner ce produit si c'est celui que l'agent
                                        // avait choisi avant le rechargement de la page.
                                        echo ($form_produits[$i] == $p['id_produit'])
                                            ? 'selected="selected"'
                                            : '';
                                        ?>
                                        <?php
                                        // Désactiver les produits en rupture de stock.
                                        // L'agent ne peut pas les sélectionner du tout.
                                        echo ($p['stock_actuel'] == 0) ? 'disabled' : '';
                                        ?>>
                                        <?php echo htmlspecialchars($p['designation']); ?>
                                        <?php
                                        // Ajouter "(rupture)" dans le libellé pour les produits
                                        // épuisés, même s'ils sont désactivés, pour l'information.
                                        echo ($p['stock_actuel'] == 0) ? ' (rupture)' : '';
                                        ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>

                            <!--
                                Champ quantité pour cette ligne.
                                name="quantite[]" : tableau côté PHP, comme id_produit[].
                                value="..." : PHP ré-injecte la quantité soumise pour
                                conserver ce que l'agent avait saisi.
                                min="1" : le navigateur empêche de saisir 0 ou un nombre négatif.
                            -->
                            <input type="number"
                                   name="quantite[]"
                                   value="<?php echo $form_quantites[$i]; ?>"
                                   min="1"
                                   style="flex:0 0 80px;">

                            <!--
                                MESSAGE D'ERREUR DE STOCK (rendu par PHP)
                                Affiché uniquement si cette ligne ($i) est dans $stock_errors.
                                La classe "stock-error-msg" positionne le texte rouge
                                en bas de la ligne (définie dans le <style>).
                            -->
                            <?php if (array_key_exists($i, $stock_errors)): ?>
                                <span class="stock-error-msg">
                                    &#x26A0; <?php echo $stock_errors[$i]; ?>
                                </span>
                            <?php endif; ?>

                        </div>

                    <?php endfor; ?>
                    <!-- Fin de la boucle des lignes produit -->

                    <!-- Bouton d'envoi du formulaire — aligné à droite -->
                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary"
                                style="padding:10px 28px; font-size:0.85rem;">
                            &#x1F4BE; Enregistrer la vente
                        </button>
                    </div>

                </form>
            </div>
        </div>

        <!-- ── TABLEAU DES VENTES RÉCENTES ── -->
        <div class="card">
            <div class="card-header">
                <h3>&#x1F4CB; Ventes r&eacute;centes</h3>
            </div>

            <?php if (mysqli_num_rows($ventes) == 0): ?>
                <!-- État vide : aucune vente enregistrée -->
                <div class="empty-state">
                    <div class="empty-icon">&#x1F6D2;</div>
                    <p>Aucune vente enregistr&eacute;e pour l'instant.</p>
                </div>
            <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Client</th>
                        <th>Agent</th>
                        <th>Paiement</th>
                        <th>Total</th>
                        <th>Date</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php while ($v = mysqli_fetch_assoc($ventes)): ?>
                    <tr>
                        <td>#<?php echo $v['id_vente']; ?></td>
                        <td>
                            <?php
                            // Si le client_nom est NULL (vente anonyme), afficher un badge.
                            // htmlspecialchars() empêche l'injection HTML dans le nom.
                            echo $v['client_nom']
                                ? htmlspecialchars($v['client_nom'])
                                : '<span class="badge badge-info">Anonyme</span>';
                            ?>
                        </td>
                        <td><?php echo htmlspecialchars($v['agent_nom']); ?></td>
                        <td><?php echo ucfirst($v['mode_paiement']); ?></td>
                        <td><strong>$<?php echo number_format($v['montant_total'], 2); ?></strong></td>
                        <td><?php echo date('d/m/Y H:i', strtotime($v['date_vente'])); ?></td>
                        <td style="display:flex; gap:6px;">
                            <!-- Lien vers la vue détail de cette vente -->
                            <a href="ventes.php?voir=<?php echo $v['id_vente']; ?>"
                               class="btn btn-secondary">
                                &#x1F50D; Voir
                            </a>
                            <!-- Lien vers la génération de facture PDF -->
                            <a href="facture.php?id=<?php echo $v['id_vente']; ?>"
                               class="btn btn-primary"
                               style="background:#C9A227; color:#1A1A1A; font-weight:700;
                                      border:none; font-size:0.8rem;">
                                &#x2B07;&#xFE0F; PDF
                            </a>
                        </td>
                    </tr>
                <?php endwhile; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>

        <?php endif; ?>
        <!-- Fin de la condition detail_vente / formulaire -->

    </main>
</div>

<!-- Aucun JavaScript dans ce fichier. -->
<!-- Toute la logique est gérée côté serveur par PHP. -->

</body>
</html>
