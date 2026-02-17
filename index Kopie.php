<?php

/*************************************************
 * CONFIG
 *************************************************/

// Lexware / lexoffice API
const LEXWARE_API_KEY      = 'your-key-here'; // TODO
const LEXWARE_RESOURCE_URL = 'https://api.lexware.io';   // new gateway URL :contentReference[oaicite:3]{index=3}

// Where to store the uploaded mapping CSV
const CSV_MAPPING_FILE = __DIR__ . '/mapping.csv';

// Your own bank data (debtor in SEPA file)
const DEBTOR_NAME = 'Name';       // TODO
const DEBTOR_IBAN = 'IBAN'; // TODO
const DEBTOR_BIC  = 'BIC';           // TODO

// Execution date for the SEPA transfer (today by default)
const SEPA_REQ_EXEC_DAYS_OFFSET = 0; // 0 = heute, 1 = morgen, etc.


/*************************************************
 * SMALL HELPERS
 *************************************************/

function normalize_name(string $name): string
{
    // Normalize for matching CSV ↔ Lexware
    $name = mb_strtolower(trim($name), 'UTF-8');
    // remove double spaces etc.
    $name = preg_replace('/\s+/', ' ', $name);
    return $name;
}

/**
 * Load mapping from CSV file.
 *
 * Expected default format (can be adapted):
 *   Name;IBAN;BIC;Date(optional, YYYY-MM-DD or DD.MM.YYYY)
 *
 * If the same name appears multiple times, the row with the
 * latest date wins (= "newest IBAN/BIC").
 */

function load_mapping(): array
{
    $mapping = [];

    if (!file_exists(CSV_MAPPING_FILE)) {
        return [];
    }

    if (($h = fopen(CSV_MAPPING_FILE, 'r')) === false) {
        return [];
    }

    while (($row = fgetcsv($h, 0, ';')) !== false) {
        // We need at least 11 columns (0–10)
        if (count($row) < 11) {
            continue;
        }

        // Your spec:
        //  - Name in 8th column  -> index 7
        //  - IBAN in 11th column -> index 10
        $name = trim($row[7] ?? '');
        $iban = preg_replace('/\s+/', '', strtoupper(trim($row[10] ?? '')));
        $bic  = ''; // still empty for now

        if ($name === '' || $iban === '') {
            continue;
        }

        $key = normalize_name($name);

        // First occurrence for this name
        if (!isset($mapping[$key])) {
            $mapping[$key] = [
                'name'        => $name,
                'iban'        => $iban,
                'bic'         => $bic,
                'ibanChanged' => false, // default: no change detected
            ];
        } else {
            // We have seen this name before – check if IBAN changed
            if ($iban !== '' && $iban !== $mapping[$key]['iban']) {
                // IBAN changed at some point in the CSV
                $mapping[$key]['ibanChanged'] = true;
                // and use the *newest* IBAN (assuming CSV is chronological)
                $mapping[$key]['iban']        = $iban;
            }
            // if IBAN is same as before → nothing to do
        }
    }

    fclose($h);
    return $mapping;
}


/**
 * Very small wrapper for Lexware API call to /v1/voucherlist
 * Filters for open credit notes (Rechnungskorrekturen).
 */
function lexware_get_open_creditnotes(): array
{
    if (LEXWARE_API_KEY === 'PASTE_YOUR_API_KEY_HERE') {
        http_response_code(500);
        echo json_encode(['error' => 'Bitte trage deinen Lexware API Key in index.php ein.']);
        exit;
    }

    // We want open credit notes. We use both "creditnote" and "salescreditnote"
    // just in case both types are used. :contentReference[oaicite:4]{index=4}
    $query = http_build_query([
        'voucherType'   => 'creditnote,salescreditnote',
        'voucherStatus' => 'open',
        'archived'      => 'false',
        'size'          => 250, // adjust if you have more than 250 offene Korrekturen
    ]);

    $url = rtrim(LEXWARE_RESOURCE_URL, '/') . '/v1/voucherlist?' . $query;

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . LEXWARE_API_KEY,
            'Accept: application/json',
        ],
    ]);

    $body = curl_exec($ch);
    if ($body === false) {
        $err = curl_error($ch);
        curl_close($ch);
        http_response_code(500);
        echo json_encode(['error' => 'cURL-Fehler beim Lexware-API-Aufruf: ' . $err]);
        exit;
    }

    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($status !== 200) {
        http_response_code(500);
        echo json_encode([
            'error'      => 'Lexware API hat Status ' . $status . ' zurückgegeben.',
            'rawBody'    => $body,
            'requestedUrl' => $url,
        ]);
        exit;
    }

    $data = json_decode($body, true);
    if (!is_array($data)) {
        http_response_code(500);
        echo json_encode(['error' => 'Antwort der Lexware API ist kein gültiges JSON.']);
        exit;
    }

    return $data;
}

/**
 * Generate SEPA pain.001.001.03 XML string for multiple credit notes.
 * $items = [
 *   [
 *     'voucherNumber' => 'CN-123',
 *     'contactName'   => 'Kunde GmbH',
 *     'amount'        => 123.45,
 *     'iban'          => 'DE…',
 *     'bic'           => 'ABCDEFGHXXX',
 *   ],
 *   ...
 * ]
 */
function generate_sepa_xml(array $items): string
{
    if (empty($items)) {
        throw new RuntimeException('Keine gültigen Datensätze für die SEPA-Datei.');
    }

    $nbTxs   = count($items);
    $ctrlSum = 0.0;
    foreach ($items as $it) {
        $ctrlSum += (float)$it['amount'];
    }

    $msgId   = 'CN-' . date('Ymd-His');
    $pmtInfId = 'PMT-' . date('Ymd-His');
    $creDtTm = date('c'); // ISO 8601
    $execDate = (new DateTime())
        ->modify('+' . (int)SEPA_REQ_EXEC_DAYS_OFFSET . ' days')
        ->format('Y-m-d');

    // Build XML as string (keeps things simple, DOMDocument would be the noble way)
    $xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    $xml .= '<Document xmlns="urn:iso:std:iso:20022:tech:xsd:pain.001.001.03">' . "\n";
    $xml .= '  <CstmrCdtTrfInitn>' . "\n";
    $xml .= '    <GrpHdr>' . "\n";
    $xml .= '      <MsgId>' . htmlspecialchars($msgId) . '</MsgId>' . "\n";
    $xml .= '      <CreDtTm>' . htmlspecialchars($creDtTm) . '</CreDtTm>' . "\n";
    $xml .= '      <NbOfTxs>' . $nbTxs . '</NbOfTxs>' . "\n";
    $xml .= '      <CtrlSum>' . number_format($ctrlSum, 2, '.', '') . '</CtrlSum>' . "\n";
    $xml .= '      <InitgPty><Nm>' . htmlspecialchars(DEBTOR_NAME) . '</Nm></InitgPty>' . "\n";
    $xml .= '    </GrpHdr>' . "\n";
    $xml .= '    <PmtInf>' . "\n";
    $xml .= '      <PmtInfId>' . htmlspecialchars($pmtInfId) . '</PmtInfId>' . "\n";
    $xml .= '      <PmtMtd>TRF</PmtMtd>' . "\n";
    $xml .= '      <BtchBookg>true</BtchBookg>' . "\n";
    $xml .= '      <NbOfTxs>' . $nbTxs . '</NbOfTxs>' . "\n";
    $xml .= '      <CtrlSum>' . number_format($ctrlSum, 2, '.', '') . '</CtrlSum>' . "\n";
    $xml .= '      <PmtTpInf><SvcLvl><Cd>SEPA</Cd></SvcLvl></PmtTpInf>' . "\n";
    $xml .= '      <ReqdExctnDt>' . $execDate . '</ReqdExctnDt>' . "\n";
    $xml .= '      <Dbtr><Nm>' . htmlspecialchars(DEBTOR_NAME) . '</Nm></Dbtr>' . "\n";
    $xml .= '      <DbtrAcct><Id><IBAN>' . htmlspecialchars(DEBTOR_IBAN) . '</IBAN></Id></DbtrAcct>' . "\n";
    $xml .= '      <DbtrAgt><FinInstnId><BIC>' . htmlspecialchars(DEBTOR_BIC) . '</BIC></FinInstnId></DbtrAgt>' . "\n";
    $xml .= '      <ChrgBr>SLEV</ChrgBr>' . "\n";

    foreach ($items as $it) {
        $endToEndId = substr(preg_replace('/\s+/', '', (string)$it['voucherNumber']), 0, 35);
        $amountStr  = number_format((float)$it['amount'], 2, '.', '');
        $cdtrName   = $it['contactName'];
        $iban       = $it['iban'];
        $bic        = $it['bic'] ?: 'NOTPROVIDED'; // some banks allow this

        $remText = 'Rechnungskorrektur ' . $it['voucherNumber'];

        $xml .= '      <CdtTrfTxInf>' . "\n";
        $xml .= '        <PmtId><EndToEndId>' . htmlspecialchars($endToEndId) . '</EndToEndId></PmtId>' . "\n";
        $xml .= '        <Amt><InstdAmt Ccy="EUR">' . $amountStr . '</InstdAmt></Amt>' . "\n";
        $xml .= '        <CdtrAgt><FinInstnId><BIC>' . htmlspecialchars($bic) . '</BIC></FinInstnId></CdtrAgt>' . "\n";
        $xml .= '        <Cdtr><Nm>' . htmlspecialchars($cdtrName) . '</Nm></Cdtr>' . "\n";
        $xml .= '        <CdtrAcct><Id><IBAN>' . htmlspecialchars($iban) . '</IBAN></Id></CdtrAcct>' . "\n";
        $xml .= '        <RmtInf><Ustrd>' . htmlspecialchars($remText) . '</Ustrd></RmtInf>' . "\n";
        $xml .= '      </CdtTrfTxInf>' . "\n";
    }

    $xml .= '    </PmtInf>' . "\n";
    $xml .= '  </CstmrCdtTrfInitn>' . "\n";
    $xml .= '</Document>' . "\n";

    return $xml;
}

/*************************************************
 * AJAX HANDLERS
 *************************************************/

$action = $_GET['action'] ?? null;

if ($action === 'uploadCsv') {
    header('Content-Type: application/json; charset=utf-8');

    if (!isset($_FILES['csv']) || $_FILES['csv']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['success' => false, 'message' => 'CSV-Upload fehlgeschlagen.']);
        exit;
    }

    if (!move_uploaded_file($_FILES['csv']['tmp_name'], CSV_MAPPING_FILE)) {
        echo json_encode(['success' => false, 'message' => 'Konnte CSV nicht speichern.']);
        exit;
    }

    // Reset mapping cache
    load_mapping(); // first load builds cache, but file has changed – simplest: unset
    echo json_encode(['success' => true, 'message' => 'CSV erfolgreich hochgeladen.']);
    exit;
}

if ($action === 'listCreditNotes') {
    header('Content-Type: application/json; charset=utf-8');

    $mapping = load_mapping();
    $data = lexware_get_open_creditnotes();

    $items = [];

    $content = $data['content'] ?? [];
    foreach ($content as $row) {
        $id           = $row['id'] ?? null;
        $voucherType  = $row['voucherType'] ?? '';
        $voucherStatus = $row['voucherStatus'] ?? '';
        $voucherNumber = $row['voucherNumber'] ?? '';
        $contactName  = $row['contactName'] ?? '';
        $openAmount   = $row['openAmount'] ?? null;
        $totalAmount  = $row['totalAmount'] ?? null;

        if (!$id || $voucherStatus !== 'open') {
            continue;
        }

        // prefer openAmount if available, fall back to totalAmount
        $amount = $openAmount !== null ? (float)$openAmount : (float)$totalAmount;

        if ($amount <= 0) {
            continue;
        }

        $key = normalize_name($contactName);
        $iban = $mapping[$key]['iban'] ?? null;
        $bic  = $mapping[$key]['bic']  ?? null;
        $ibanChanged = $mapping[$key]['ibanChanged'] ?? false;

        $items[] = [
            'id'           => $id,
            'voucherType'  => $voucherType,
            'voucherStatus' => $voucherStatus,
            'voucherNumber' => $voucherNumber,
            'contactName'  => $contactName,
            'amount'       => $amount,
            'iban'         => $iban,
            'bic'          => $bic,
            'hasMapping'   => $iban ? true : false,
            'ibanChanged'  => $ibanChanged,
        ];
    }

    echo json_encode([
        'success' => true,
        'items'   => $items,
        'hasCsv'  => file_exists(CSV_MAPPING_FILE),
    ]);
    exit;
}

if ($action === 'generateXml') {
    // This returns the XML file as a download
    $json = file_get_contents('php://input');
    $payload = json_decode($json, true);

    if (!is_array($payload) || !isset($payload['items']) || !is_array($payload['items'])) {
        http_response_code(400);
        echo 'Invalid payload';
        exit;
    }

    // Re-load mapping to ensure latest IBAN/BIC (and "newest" entries)
    $mapping = load_mapping();
    $sepaItems = [];

    foreach ($payload['items'] as $it) {
        $contactName   = $it['contactName'] ?? '';
        $voucherNumber = $it['voucherNumber'] ?? '';
        $amount        = isset($it['amount']) ? (float)$it['amount'] : 0;

        if ($amount <= 0 || $contactName === '') {
            continue;
        }

        $key = normalize_name($contactName);

        // Manual override?
        $manualIban = trim($it['manualIban'] ?? '');
        $manualBic  = trim($it['manualBic'] ?? '');

        if ($manualIban !== '') {
            $iban = strtoupper(str_replace(' ', '', $manualIban));
            $bic  = strtoupper($manualBic ?: 'NOTPROVIDED');
        } else {
            // Fallback to CSV mapping if no manual entry
            if (!isset($mapping[$key])) {
                continue;
            }
            $iban = $mapping[$key]['iban'];
            $bic  = $mapping[$key]['bic'] ?: 'NOTPROVIDED';
        }

        if ($iban === '') {
            continue;
        }

        $sepaItems[] = [
            'voucherNumber' => $voucherNumber,
            'contactName'   => $contactName,
            'amount'        => $amount,
            'iban'          => $iban,
            'bic'           => $bic,
        ];
    }

    if (empty($sepaItems)) {
        http_response_code(400);
        echo 'Keine gültigen Einträge mit IBAN/BIC gefunden.';
        exit;
    }

    $xml = generate_sepa_xml($sepaItems);

    $filename = 'sepa_creditnotes_' . date('Ymd_His') . '.xml';
    header('Content-Type: application/xml; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    echo $xml;
    exit;
}

?>
<!doctype html>
<html lang="de">

<head>
    <meta charset="utf-8">
    <title>Lexware Rechnungskorrekturen → SEPA XML</title>
    <style>
        body {
            font-family: system-ui, sans-serif;
            margin: 20px;
        }

        h1 {
            font-size: 1.4rem;
        }

        .card {
            border: 1px solid #ddd;
            border-radius: 6px;
            padding: 15px;
            margin-bottom: 20px;
        }

        table {
            border-collapse: collapse;
            width: 100%;
            margin-top: 10px;
        }

        th,
        td {
            border: 1px solid #ccc;
            padding: 4px 6px;
            font-size: 0.9rem;
        }

        th {
            background: #f3f3f3;
        }

        button {
            padding: 6px 12px;
            margin-top: 5px;
            cursor: pointer;
        }

        .ok {
            color: green;
        }

        .missing {
            color: red;
        }

        .small {
            font-size: 0.8rem;
            color: #666;
        }
    </style>
</head>

<body>
    <h1>Rechnungskorrekturen → SEPA XML</h1>

    <div class="card">
        <h2>1. Mapping-CSV hochladen (Name → IBAN/BIC)</h2>
        <form id="csvForm">
            <input type="file" name="csv" accept=".csv" required>
            <button type="submit">CSV hochladen</button>
        </form>
        <div id="csvStatus" class="small"></div>
        <p class="small">
            Erwartetes Standardformat (anpassbar im PHP-Code):<br>
            <code>Name;IBAN;BIC;Datum(optional)</code><br>
            Bei mehreren Zeilen mit gleichem Namen gewinnt der Eintrag mit dem neuesten Datum.
        </p>
    </div>

    <div class="card">
        <h2>2. Offene Rechnungskorrekturen aus Lexware laden</h2>
        <button id="loadBtn">Offene Rechnungskorrekturen laden</button>
        <div id="loadStatus" class="small"></div>
        <div id="tableContainer"></div>
        <button id="xmlBtn" disabled>SEPA XML für ausgewählte erzeugen</button>
        <div id="xmlStatus" class="small"></div>
    </div>

    <script>
        const csvForm = document.getElementById('csvForm');
        const csvStatus = document.getElementById('csvStatus');
        const loadBtn = document.getElementById('loadBtn');
        const loadStatus = document.getElementById('loadStatus');
        const tableContainer = document.getElementById('tableContainer');
        const xmlBtn = document.getElementById('xmlBtn');
        const xmlStatus = document.getElementById('xmlStatus');

        let currentItems = [];

        csvForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            csvStatus.textContent = 'Lade CSV hoch...';

            const formData = new FormData(csvForm);
            try {
                const res = await fetch('?action=uploadCsv', {
                    method: 'POST',
                    body: formData
                });
                const data = await res.json();
                csvStatus.textContent = data.message || 'Fertig.';
            } catch (e) {
                console.error(e);
                csvStatus.textContent = 'Fehler beim Hochladen.';
            }
        });

        loadBtn.addEventListener('click', async () => {
            loadStatus.textContent = 'Lade Daten aus Lexware...';
            tableContainer.innerHTML = '';
            xmlBtn.disabled = true;
            xmlStatus.textContent = '';

            try {
                const res = await fetch('?action=listCreditNotes');
                const data = await res.json();

                if (!data.success) {
                    loadStatus.textContent = data.error || 'Fehler beim Laden.';
                    return;
                }

                currentItems = data.items || [];
                if (currentItems.length === 0) {
                    loadStatus.textContent = 'Keine offenen Rechnungskorrekturen gefunden.';
                    return;
                }

                loadStatus.textContent = `Gefundene offene Rechnungskorrekturen: ${currentItems.length}`;

                const table = document.createElement('table');
                const thead = document.createElement('thead');
                thead.innerHTML = `
            <tr>
                <th><input type="checkbox" id="selectAll"></th>
                <th>Nr.</th>
                <th>Kunde</th>
                <th>Betrag</th>
                <th>IBAN aus CSV</th>
                <th>BIC aus CSV</th>
                <th>Mapping</th>
                <th>IBAN geändert?</th>
            </tr>
        `;
                table.appendChild(thead);

                const tbody = document.createElement('tbody');
                currentItems.forEach((it, idx) => {
                    const tr = document.createElement('tr');
                    tr.dataset.index = idx;

                    const hasMapping = it.hasMapping;
                    const ibanChanged = it.ibanChanged;

                    if (ibanChanged) {
                        tr.classList.add('iban-changed-row'); // highlight whole row
                    }

                    let ibanField, bicField;

                    // If no mapping, create input fields
                    if (!hasMapping) {
                        ibanField = `<input type="text" class="manual-iban" placeholder="IBAN eingeben">`;
                        bicField = `<input type="text" class="manual-bic" placeholder="BIC (optional)">`;
                    } else {
                        ibanField = it.iban || '';
                        bicField = it.bic || '';
                    }
                    tr.innerHTML = `
    <td><input type="checkbox" class="rowCheck"></td>
    <td>${it.voucherNumber || ''}</td>
    <td>${it.contactName || ''}</td>
    <td style="text-align:right">${(it.amount ?? 0).toFixed(2)} €</td>
    <td>${ibanField}</td>
    <td>${bicField}</td>
    <td>${hasMapping ? '<span class="ok">OK</span>' : '<span class="missing">kein Mapping</span>'}</td>
    <td>${ibanChanged ? '<span class="warn">Ja (IBAN gewechselt)</span>' : ''}</td>
`;
                    tbody.appendChild(tr);
                });

                table.appendChild(tbody);
                tableContainer.innerHTML = '';
                tableContainer.appendChild(table);

                // select all checkbox
                const selectAll = document.getElementById('selectAll');
                const checkboxes = table.querySelectorAll('.rowCheck');

                selectAll.addEventListener('change', () => {
                    checkboxes.forEach(cb => cb.checked = selectAll.checked);
                    xmlBtn.disabled = !anySelected();
                });

                checkboxes.forEach(cb => {
                    cb.addEventListener('change', () => {
                        xmlBtn.disabled = !anySelected();
                    });
                });


            } catch (e) {
                console.error(e);
                loadStatus.textContent = 'Fehler beim Laden.';
            }
        });

        function anySelected() {
            return !!document.querySelector('.rowCheck:checked');
        }

        xmlBtn.addEventListener('click', async () => {
            xmlStatus.textContent = 'Erzeuge SEPA XML...';

            const rows = document.querySelectorAll('tbody tr');
            const items = [];
            rows.forEach(tr => {
                const idx = parseInt(tr.dataset.index, 10);
                const cb = tr.querySelector('.rowCheck');
                if (cb && cb.checked) {
                    const it = currentItems[idx];
                    const ibanInput = tr.querySelector('.manual-iban');
                    const bicInput = tr.querySelector('.manual-bic');

                    items.push({
                        voucherNumber: it.voucherNumber,
                        contactName: it.contactName,
                        amount: it.amount,
                        manualIban: ibanInput ? ibanInput.value.trim() : null,
                        manualBic: bicInput ? bicInput.value.trim() : null
                    });
                }
            });

            if (items.length === 0) {
                xmlStatus.textContent = 'Nichts ausgewählt.';
                return;
            }

            try {
                const res = await fetch('?action=generateXml', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        items
                    })
                });

                if (!res.ok) {
                    const text = await res.text();
                    xmlStatus.textContent = 'Fehler: ' + text;
                    return;
                }

                const blob = await res.blob();
                const url = URL.createObjectURL(blob);
                const a = document.createElement('a');
                a.href = url;
                a.download = 'sepa_creditnotes_' + new Date().toISOString().replace(/[-:T]/g, '').slice(0, 14) + '.xml';
                document.body.appendChild(a);
                a.click();
                a.remove();
                URL.revokeObjectURL(url);

                xmlStatus.textContent = 'SEPA XML heruntergeladen.';
            } catch (e) {
                console.error(e);
                xmlStatus.textContent = 'Fehler bei der XML-Erzeugung.';
            }
        });
    </script>
</body>

</html>