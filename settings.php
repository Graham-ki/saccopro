<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
$user = require_admin();

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check($_POST['csrf'] ?? null);

    $pairs = [
        'org_name'          => trim((string)($_POST['org_name'] ?? '')),
        'org_short_name'    => trim((string)($_POST['org_short_name'] ?? '')),
        'currency_code'     => strtoupper(trim((string)($_POST['currency_code'] ?? 'UGX'))),
        'currency_symbol'   => trim((string)($_POST['currency_symbol'] ?? 'UGX')),
        'currency_position' => ($_POST['currency_position'] ?? 'before') === 'after' ? 'after' : 'before',
        'receipt_footer'    => trim((string)($_POST['receipt_footer'] ?? '')),
        'default_interest'  => trim((string)($_POST['default_interest'] ?? '3')),
        'default_term'      => trim((string)($_POST['default_term'] ?? '6')),
        'default_method'    => in_array($_POST['default_method'] ?? '', ['flat','declining'], true)
                                ? $_POST['default_method'] : 'declining',
        'timezone'          => trim((string)($_POST['timezone'] ?? 'Africa/Kampala')),
        'share_face_value'     => trim((string)($_POST['share_face_value'] ?? '10000')),
    'share_label'          => trim((string)($_POST['share_label'] ?? 'Ordinary  Shares')),
'dividend_min_months'  => trim((string)($_POST['dividend_min_months'] ?? '6')),
'dividend_default_pct' => trim((string)($_POST['dividend_default_pct'] ?? '100')),
    ];

    if ($pairs['org_name'] === '') $errors[] = 'Organization name is required.';
    if ($pairs['org_short_name'] === '') $pairs['org_short_name'] = 'SCMS';
    if ($pairs['currency_symbol'] === '') $pairs['currency_symbol'] = 'UGX';
    if ($pairs['currency_code'] === '')   $pairs['currency_code']   = 'UGX';
    if (!is_numeric($pairs['default_interest']) || (float)$pairs['default_interest'] < 0)
        $errors[] = 'Default interest rate must be 0 or greater.';
    if (!is_numeric($pairs['default_term']) || (int)$pairs['default_term'] < 1)
        $errors[] = 'Default term must be at least 1 month.';
    if (!is_numeric($pairs['share_face_value']) || (float)$pairs['share_face_value'] <= 0)
    $errors[] = 'Share face value must be greater than 0.';
if (!is_numeric($pairs['dividend_min_months']) || (int)$pairs['dividend_min_months'] < 0)
    $errors[] = 'Minimum months must be 0 or more.';
if (!is_numeric($pairs['dividend_default_pct']) || (float)$pairs['dividend_default_pct'] < 0 || (float)$pairs['dividend_default_pct'] > 100)
    $errors[] = 'Dividend % must be 0–100.';
    if (!$errors) {
        save_settings($pdo, $pairs);
        audit_log($pdo, (int)$user['id'], 'settings.update', 'settings', null, 'Updated application settings');
        flash('success', 'Settings saved.');
        redirect('/scms/settings.php');
    }
}

$pageTitle = 'Settings';
require_once __DIR__ . '/includes/header.php';
?>
<style>
.field { display:flex; flex-direction:column; gap:6px; font-size:.85rem; font-weight:600; color:var(--text-2); }
.field input, .field select, .field textarea {
    padding:11px 12px; border:1.5px solid var(--border); border-radius:8px;
    background:var(--surface); color:var(--text); font:inherit; font-size:.95rem;
    width:100%;
}
.field input:focus, .field select:focus, .field textarea:focus {
    outline:none; border-color:var(--primary); box-shadow:0 0 0 4px rgba(79,70,229,.12);
}
.grid-2 { display:grid; grid-template-columns:1fr 1fr; gap:16px; }
.grid-3 { display:grid; grid-template-columns:1fr 1fr 1fr; gap:16px; }
@media (max-width:700px) {
    .grid-2, .grid-3 { grid-template-columns:1fr; }
}

/* ---- Currency picker ---- */
.currency-dropdown {
    position:absolute; top:calc(100% + 4px); left:0; right:0;
    background:var(--surface); border:1.5px solid var(--border); border-radius:10px;
    box-shadow:0 12px 32px rgba(0,0,0,.12); max-height:280px; overflow-y:auto;
    z-index:50;
}
.currency-option {
    display:flex; align-items:center; gap:10px;
    padding:9px 12px; cursor:pointer; font-size:.9rem; font-weight:500;
    color:var(--text); border-bottom:1px solid var(--border);
}
.currency-option:last-child { border-bottom:none; }
.currency-option:hover, .currency-option.active { background:rgba(79,70,229,.08); }
.currency-option .code { font-weight:700; min-width:52px; color:var(--primary); }
.currency-option .name { flex:1; color:var(--text-2); font-weight:400; }
.currency-option .symbol { font-weight:600; color:var(--text-2); }
.currency-empty { padding:14px; text-align:center; color:var(--text-2); font-size:.85rem; }

.currency-preview {
    padding:11px 12px; border:1.5px dashed var(--border); border-radius:8px;
    background:var(--surface); color:var(--text); font-size:1.05rem; font-weight:700;
    letter-spacing:.3px;
}
</style>
<div class="page-head">
    <div>
        <h1>Settings</h1>
        <p>Configure your organization and default values.</p>
    </div>
</div>

<?php if ($msg = flash('success')): ?>
    <div class="alert alert-success"><?= e($msg) ?></div>
<?php endif; ?>
<?php if ($errors): ?>
    <div class="alert alert-error">
        <strong>Please fix:</strong>
        <ul style="margin-top:8px;">
            <?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<form method="post" class="card" style="max-width:900px;">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">

    <h3 class="card-title" style="margin-bottom:16px;">Organization</h3>
    <div class="grid-2">
        <label class="field"><span>Organization name *</span>
            <input type="text" name="org_name" value="<?= e(setting('org_name')) ?>" required>
        </label>
        <label class="field"><span>Short name / brand</span>
            <input type="text" name="org_short_name" value="<?= e(setting('org_short_name')) ?>">
        </label>
    </div>

    <h3 class="card-title" style="margin:24px 0 16px;">Currency</h3>
    <div class="grid-2">
        <label class="field" style="position:relative;">
            <span>Currency</span>
            <input type="text"
                   id="currencySearch"
                   name="currency_code"
                   value="<?= e(setting('currency_code') ?: 'UGX') ?>"
                   placeholder="Type to search (e.g. UGX, USD, EUR...)"
                   autocomplete="off"
                   required>
            <div id="currencyDropdown" class="currency-dropdown" hidden></div>
            <small id="currencyHint" style="color:var(--text-2);font-weight:500;margin-top:4px;"></small>
        </label>
        <label class="field"><span>Symbol</span>
            <input type="text" id="currencySymbol" name="currency_symbol" value="<?= e(setting('currency_symbol')) ?>" maxlength="6">
        </label>
    </div>

    <div class="grid-2" style="margin-top:16px;">
        <label class="field"><span>Position</span>
            <select name="currency_position" id="currencyPosition">
                <option value="before" <?= setting('currency_position') === 'before' ? 'selected' : '' ?>>Before (symbol 1,000)</option>
                <option value="after"  <?= setting('currency_position') === 'after'  ? 'selected' : '' ?>>After (1,000 symbol)</option>
            </select>
        </label>
        <div class="field">
            <span>Live preview</span>
            <div id="currencyPreview" class="currency-preview">UGX 1,234,567.89</div>
        </div>
    </div>

    <h3 class="card-title" style="margin:24px 0 16px;">Loan defaults</h3>
    <div class="grid-3">
        <label class="field"><span>Interest rate (%/month)</span>
            <input type="number" step="0.001" min="0" name="default_interest" value="<?= e(setting('default_interest')) ?>">
        </label>
        <label class="field"><span>Term (months)</span>
            <input type="number" min="1" name="default_term" value="<?= e(setting('default_term')) ?>">
        </label>
        <label class="field"><span>Interest method</span>
            <select name="default_method">
                <option value="declining" <?= setting('default_method') === 'declining' ? 'selected' : '' ?>>Declining balance</option>
                <option value="flat"      <?= setting('default_method') === 'flat'      ? 'selected' : '' ?>>Flat</option>
            </select>
        </label>
    </div>
      <h3 class="card-title" style="margin:24px 0 16px;">Shares &amp; dividends</h3>
<div class="grid-2">
    <label class="field"><span>Share label</span>
        <input type="text" name="share_label" value="<?= e(setting('share_label', 'Ordinary Shares')) ?>">
    </label>
    <label class="field"><span>Face value per share</span>
        <input type="number" step="0.01" min="0.01" name="share_face_value"
               value="<?= e(setting('share_face_value', '10000')) ?>">
    </label>
</div>
<div class="grid-2">
    <label class="field"><span>Minimum membership months for dividend</span>
        <input type="number" min="0" name="dividend_min_months"
               value="<?= e(setting('dividend_min_months', '6')) ?>">
    </label>
    <label class="field"><span>Default distribution %</span>
        <input type="number" min="0" max="100" step="0.01" name="dividend_default_pct"
               value="<?= e(setting('dividend_default_pct', '100')) ?>">
    </label>
</div>          
    <h3 class="card-title" style="margin:24px 0 16px;">Receipts &amp; locale</h3>
    <label class="field"><span>Receipt footer</span>
        <textarea name="receipt_footer" rows="2"><?= e(setting('receipt_footer')) ?></textarea>
    </label>
    <label class="field"><span>Timezone</span>
        <input type="text" name="timezone" value="<?= e(setting('timezone')) ?>" placeholder="Africa/Kampala">
    </label>

    <div style="margin-top:24px;text-align:right;">
        <button class="btn btn-primary">Save settings</button>
    </div>
</form>



<script>
// ============================================================
// World currency list (code, name, symbol)
// ============================================================
const CURRENCIES = [
    { code:'AED', name:'UAE Dirham',              symbol:'د.إ' },
    { code:'AFN', name:'Afghan Afghani',          symbol:'؋' },
    { code:'ALL', name:'Albanian Lek',            symbol:'L' },
    { code:'AMD', name:'Armenian Dram',           symbol:'֏' },
    { code:'ANG', name:'Netherlands Antillian Guilder', symbol:'ƒ' },
    { code:'AOA', name:'Angolan Kwanza',          symbol:'Kz' },
    { code:'ARS', name:'Argentine Peso',          symbol:'$' },
    { code:'AUD', name:'Australian Dollar',       symbol:'A$' },
    { code:'AWG', name:'Aruban Florin',           symbol:'ƒ' },
    { code:'AZN', name:'Azerbaijani Manat',       symbol:'₼' },
    { code:'BAM', name:'Bosnia-Herzegovina Mark', symbol:'KM' },
    { code:'BBD', name:'Barbadian Dollar',        symbol:'Bds$' },
    { code:'BDT', name:'Bangladeshi Taka',        symbol:'৳' },
    { code:'BGN', name:'Bulgarian Lev',           symbol:'лв' },
    { code:'BHD', name:'Bahraini Dinar',          symbol:'.د.ب' },
    { code:'BIF', name:'Burundian Franc',         symbol:'FBu' },
    { code:'BMD', name:'Bermudan Dollar',         symbol:'BD$' },
    { code:'BND', name:'Brunei Dollar',           symbol:'B$' },
    { code:'BOB', name:'Bolivian Boliviano',      symbol:'Bs.' },
    { code:'BRL', name:'Brazilian Real',          symbol:'R$' },
    { code:'BSD', name:'Bahamian Dollar',         symbol:'B$' },
    { code:'BTN', name:'Bhutanese Ngultrum',      symbol:'Nu.' },
    { code:'BWP', name:'Botswanan Pula',          symbol:'P' },
    { code:'BYN', name:'Belarusian Ruble',        symbol:'Br' },
    { code:'BZD', name:'Belize Dollar',           symbol:'BZ$' },
    { code:'CAD', name:'Canadian Dollar',         symbol:'C$' },
    { code:'CDF', name:'Congolese Franc',         symbol:'FC' },
    { code:'CHF', name:'Swiss Franc',             symbol:'CHF' },
    { code:'CLP', name:'Chilean Peso',            symbol:'$' },
    { code:'CNY', name:'Chinese Yuan',            symbol:'¥' },
    { code:'COP', name:'Colombian Peso',          symbol:'$' },
    { code:'CRC', name:'Costa Rican Colón',       symbol:'₡' },
    { code:'CUP', name:'Cuban Peso',              symbol:'₱' },
    { code:'CVE', name:'Cape Verdean Escudo',     symbol:'$' },
    { code:'CZK', name:'Czech Koruna',            symbol:'Kč' },
    { code:'DJF', name:'Djiboutian Franc',        symbol:'Fdj' },
    { code:'DKK', name:'Danish Krone',            symbol:'kr' },
    { code:'DOP', name:'Dominican Peso',          symbol:'RD$' },
    { code:'DZD', name:'Algerian Dinar',          symbol:'د.ج' },
    { code:'EGP', name:'Egyptian Pound',          symbol:'£' },
    { code:'ERN', name:'Eritrean Nakfa',          symbol:'Nfk' },
    { code:'ETB', name:'Ethiopian Birr',          symbol:'Br' },
    { code:'EUR', name:'Euro',                    symbol:'€' },
    { code:'FJD', name:'Fijian Dollar',           symbol:'FJ$' },
    { code:'FKP', name:'Falkland Islands Pound',  symbol:'£' },
    { code:'GBP', name:'British Pound',           symbol:'£' },
    { code:'GEL', name:'Georgian Lari',           symbol:'₾' },
    { code:'GHS', name:'Ghanaian Cedi',           symbol:'₵' },
    { code:'GIP', name:'Gibraltar Pound',         symbol:'£' },
    { code:'GMD', name:'Gambian Dalasi',          symbol:'D' },
    { code:'GNF', name:'Guinean Franc',           symbol:'FG' },
    { code:'GTQ', name:'Guatemalan Quetzal',      symbol:'Q' },
    { code:'GYD', name:'Guyanaese Dollar',        symbol:'G$' },
    { code:'HKD', name:'Hong Kong Dollar',        symbol:'HK$' },
    { code:'HNL', name:'Honduran Lempira',        symbol:'L' },
    { code:'HRK', name:'Croatian Kuna',           symbol:'kn' },
    { code:'HTG', name:'Haitian Gourde',          symbol:'G' },
    { code:'HUF', name:'Hungarian Forint',        symbol:'Ft' },
    { code:'IDR', name:'Indonesian Rupiah',       symbol:'Rp' },
    { code:'ILS', name:'Israeli New Shekel',      symbol:'₪' },
    { code:'INR', name:'Indian Rupee',            symbol:'₹' },
    { code:'IQD', name:'Iraqi Dinar',             symbol:'ع.د' },
    { code:'IRR', name:'Iranian Rial',            symbol:'﷼' },
    { code:'ISK', name:'Icelandic Króna',         symbol:'kr' },
    { code:'JMD', name:'Jamaican Dollar',         symbol:'J$' },
    { code:'JOD', name:'Jordanian Dinar',         symbol:'د.ا' },
    { code:'JPY', name:'Japanese Yen',            symbol:'¥' },
    { code:'KES', name:'Kenyan Shilling',         symbol:'KSh' },
    { code:'KGS', name:'Kyrgystani Som',          symbol:'с' },
    { code:'KHR', name:'Cambodian Riel',          symbol:'៛' },
    { code:'KMF', name:'Comorian Franc',          symbol:'CF' },
    { code:'KPW', name:'North Korean Won',        symbol:'₩' },
    { code:'KRW', name:'South Korean Won',        symbol:'₩' },
    { code:'KWD', name:'Kuwaiti Dinar',           symbol:'د.ك' },
    { code:'KYD', name:'Cayman Islands Dollar',   symbol:'CI$' },
    { code:'KZT', name:'Kazakhstani Tenge',       symbol:'₸' },
    { code:'LAK', name:'Laotian Kip',             symbol:'₭' },
    { code:'LBP', name:'Lebanese Pound',          symbol:'ل.ل' },
    { code:'LKR', name:'Sri Lankan Rupee',        symbol:'₨' },
    { code:'LRD', name:'Liberian Dollar',         symbol:'L$' },
    { code:'LSL', name:'Lesotho Loti',            symbol:'L' },
    { code:'LYD', name:'Libyan Dinar',            symbol:'ل.د' },
    { code:'MAD', name:'Moroccan Dirham',         symbol:'د.م.' },
    { code:'MDL', name:'Moldovan Leu',            symbol:'L' },
    { code:'MGA', name:'Malagasy Ariary',         symbol:'Ar' },
    { code:'MKD', name:'Macedonian Denar',        symbol:'ден' },
    { code:'MMK', name:'Myanmar Kyat',            symbol:'K' },
    { code:'MNT', name:'Mongolian Tugrik',        symbol:'₮' },
    { code:'MOP', name:'Macanese Pataca',         symbol:'MOP$' },
    { code:'MRU', name:'Mauritanian Ouguiya',     symbol:'UM' },
    { code:'MUR', name:'Mauritian Rupee',         symbol:'₨' },
    { code:'MVR', name:'Maldivian Rufiyaa',       symbol:'Rf' },
    { code:'MWK', name:'Malawian Kwacha',         symbol:'MK' },
    { code:'MXN', name:'Mexican Peso',            symbol:'$' },
    { code:'MYR', name:'Malaysian Ringgit',       symbol:'RM' },
    { code:'MZN', name:'Mozambican Metical',      symbol:'MT' },
    { code:'NAD', name:'Namibian Dollar',         symbol:'N$' },
    { code:'NGN', name:'Nigerian Naira',          symbol:'₦' },
    { code:'NIO', name:'Nicaraguan Córdoba',      symbol:'C$' },
    { code:'NOK', name:'Norwegian Krone',         symbol:'kr' },
    { code:'NPR', name:'Nepalese Rupee',          symbol:'₨' },
    { code:'NZD', name:'New Zealand Dollar',      symbol:'NZ$' },
    { code:'OMR', name:'Omani Rial',              symbol:'ر.ع.' },
    { code:'PAB', name:'Panamanian Balboa',       symbol:'B/.' },
    { code:'PEN', name:'Peruvian Sol',            symbol:'S/.' },
    { code:'PGK', name:'Papua New Guinean Kina',  symbol:'K' },
    { code:'PHP', name:'Philippine Peso',         symbol:'₱' },
    { code:'PKR', name:'Pakistani Rupee',         symbol:'₨' },
    { code:'PLN', name:'Polish Zloty',            symbol:'zł' },
    { code:'PYG', name:'Paraguayan Guarani',      symbol:'₲' },
    { code:'QAR', name:'Qatari Rial',             symbol:'ر.ق' },
    { code:'RON', name:'Romanian Leu',            symbol:'lei' },
    { code:'RSD', name:'Serbian Dinar',           symbol:'дин' },
    { code:'RUB', name:'Russian Ruble',           symbol:'₽' },
    { code:'RWF', name:'Rwandan Franc',           symbol:'FRw' },
    { code:'SAR', name:'Saudi Riyal',             symbol:'ر.س' },
    { code:'SBD', name:'Solomon Islands Dollar',  symbol:'SI$' },
    { code:'SCR', name:'Seychellois Rupee',       symbol:'₨' },
    { code:'SDG', name:'Sudanese Pound',          symbol:'ج.س.' },
    { code:'SEK', name:'Swedish Krona',           symbol:'kr' },
    { code:'SGD', name:'Singapore Dollar',        symbol:'S$' },
    { code:'SHP', name:'Saint Helena Pound',      symbol:'£' },
    { code:'SLE', name:'Sierra Leonean Leone',    symbol:'Le' },
    { code:'SOS', name:'Somali Shilling',         symbol:'Sh' },
    { code:'SRD', name:'Surinamese Dollar',       symbol:'$' },
    { code:'SSP', name:'South Sudanese Pound',    symbol:'£' },
    { code:'STN', name:'São Tomé & Príncipe Dobra', symbol:'Db' },
    { code:'SVC', name:'Salvadoran Colón',        symbol:'₡' },
    { code:'SYP', name:'Syrian Pound',            symbol:'£' },
    { code:'SZL', name:'Swazi Lilangeni',         symbol:'E' },
    { code:'THB', name:'Thai Baht',               symbol:'฿' },
    { code:'TJS', name:'Tajikistani Somoni',      symbol:'ЅМ' },
    { code:'TMT', name:'Turkmenistani Manat',     symbol:'m' },
    { code:'TND', name:'Tunisian Dinar',          symbol:'د.ت' },
    { code:'TOP', name:'Tongan Paʻanga',          symbol:'T$' },
    { code:'TRY', name:'Turkish Lira',            symbol:'₺' },
    { code:'TTD', name:'Trinidad & Tobago Dollar',symbol:'TT$' },
    { code:'TWD', name:'New Taiwan Dollar',       symbol:'NT$' },
    { code:'TZS', name:'Tanzanian Shilling',      symbol:'TSh' },
    { code:'UAH', name:'Ukrainian Hryvnia',       symbol:'₴' },
    { code:'UGX', name:'Ugandan Shilling',        symbol:'USh' },
    { code:'USD', name:'US Dollar',               symbol:'$' },
    { code:'UYU', name:'Uruguayan Peso',          symbol:'$U' },
    { code:'UZS', name:'Uzbekistani Som',         symbol:'so\'m' },
    { code:'VES', name:'Venezuelan Bolívar',      symbol:'Bs' },
    { code:'VND', name:'Vietnamese Dong',         symbol:'₫' },
    { code:'VUV', name:'Vanuatu Vatu',            symbol:'VT' },
    { code:'WST', name:'Samoan Tala',             symbol:'WS$' },
    { code:'XAF', name:'Central African CFA Franc', symbol:'FCFA' },
    { code:'XCD', name:'East Caribbean Dollar',   symbol:'EC$' },
    { code:'XOF', name:'West African CFA Franc',  symbol:'CFA' },
    { code:'XPF', name:'CFP Franc',               symbol:'₣' },
    { code:'YER', name:'Yemeni Rial',             symbol:'﷼' },
    { code:'ZAR', name:'South African Rand',      symbol:'R' },
    { code:'ZMW', name:'Zambian Kwacha',          symbol:'ZK' },
    { code:'ZWL', name:'Zimbabwean Dollar',       symbol:'Z$' }
];

// ============================================================
// Currency picker logic
// ============================================================
(function () {
    const searchInput = document.getElementById('currencySearch');
    const symbolInput = document.getElementById('currencySymbol');
    const dropdown    = document.getElementById('currencyDropdown');
    const hint        = document.getElementById('currencyHint');
    const positionSel = document.getElementById('currencyPosition');
    const preview     = document.getElementById('currencyPreview');

    let activeIndex = -1;
    let currentList = [];

    // ---------- Live preview ----------
    function formatPreview() {
        const sym  = symbolInput.value.trim() || 'UGX';
        const pos  = positionSel.value;
        const num  = '1,234,567.89';
        preview.textContent = pos === 'after' ? `${num} ${sym}` : `${sym} ${num}`;
    }

    // ---------- Dropdown render ----------
    function renderDropdown(query) {
        const q = query.trim().toLowerCase();
        if (q === '') {
            dropdown.hidden = true;
            dropdown.innerHTML = '';
            currentList = [];
            return;
        }

        // Match against code, name, or symbol
        currentList = CURRENCIES.filter(c =>
            c.code.toLowerCase().includes(q) ||
            c.name.toLowerCase().includes(q) ||
            c.symbol.toLowerCase().includes(q)
        ).slice(0, 50);

        if (currentList.length === 0) {
            dropdown.innerHTML = `<div class="currency-empty">No currency found for “${escapeHtml(query)}”</div>`;
            dropdown.hidden = false;
            activeIndex = -1;
            return;
        }

        dropdown.innerHTML = currentList.map((c, i) => `
            <div class="currency-option" data-index="${i}" data-code="${c.code}" data-symbol="${escapeHtml(c.symbol)}">
                <span class="code">${c.code}</span>
                <span class="name">${escapeHtml(c.name)}</span>
                <span class="symbol">${escapeHtml(c.symbol)}</span>
            </div>
        `).join('');
        dropdown.hidden = false;
        activeIndex = -1;

        // Click handler
        dropdown.querySelectorAll('.currency-option').forEach(el => {
            el.addEventListener('mousedown', (ev) => {
                ev.preventDefault(); // keep focus
                selectCurrency(el.dataset.code, el.dataset.symbol);
            });
        });
    }

    // ---------- Select a currency ----------
    function selectCurrency(code, symbol) {
        searchInput.value = code;
        symbolInput.value = symbol;
        dropdown.hidden = true;
        dropdown.innerHTML = '';
        currentList = [];
        activeIndex = -1;
        updateHint(code);
        formatPreview();
    }

    // ---------- Hint below input ----------
    function updateHint(code) {
        const found = CURRENCIES.find(c => c.code === code.toUpperCase());
        hint.textContent = found ? `${found.name} (${found.symbol})` : '';
    }

    // ---------- Highlight active option ----------
    function highlight(index) {
        dropdown.querySelectorAll('.currency-option').forEach((el, i) => {
            el.classList.toggle('active', i === index);
            if (i === index) el.scrollIntoView({ block: 'nearest' });
        });
    }

    // ---------- Events ----------
    searchInput.addEventListener('input', (e) => {
        renderDropdown(e.target.value);
        updateHint(e.target.value);
    });

    searchInput.addEventListener('focus', () => {
        if (searchInput.value.trim() !== '') renderDropdown(searchInput.value);
    });

    searchInput.addEventListener('keydown', (e) => {
        if (dropdown.hidden) return;

        if (e.key === 'ArrowDown') {
            e.preventDefault();
            activeIndex = Math.min(activeIndex + 1, currentList.length - 1);
            highlight(activeIndex);
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            activeIndex = Math.max(activeIndex - 1, 0);
            highlight(activeIndex);
        } else if (e.key === 'Enter') {
            if (activeIndex >= 0 && currentList[activeIndex]) {
                e.preventDefault();
                selectCurrency(currentList[activeIndex].code, currentList[activeIndex].symbol);
            }
        } else if (e.key === 'Escape') {
            dropdown.hidden = true;
        }
    });

    // Close on outside click
    document.addEventListener('click', (e) => {
        if (!searchInput.contains(e.target) && !dropdown.contains(e.target)) {
            dropdown.hidden = true;
        }
    });

    // Live preview while typing symbol / changing position
    symbolInput.addEventListener('input', formatPreview);
    positionSel.addEventListener('change', formatPreview);

    // ---------- Init on page load ----------
    const initial = searchInput.value.trim();
    if (initial) {
        const found = CURRENCIES.find(c => c.code === initial.toUpperCase());
        if (found && !symbolInput.value) symbolInput.value = found.symbol;
        updateHint(initial);
    }
    formatPreview();

    // ---------- Helpers ----------
    function escapeHtml(s) {
        return String(s).replace(/[&<>"']/g, m => ({
            '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'
        }[m]));
    }
})();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>