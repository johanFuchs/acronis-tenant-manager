<?php
declare(strict_types=1);

/**
 * Acronis Tenant Beheer Script
 *
 * Dit script biedt klanten de mogelijkheid om hun Acronis-tenants te beheren
 * via de TransIP API. Het biedt functies voor het upgraden/downgraden van tenants
 * en het toevoegen/verwijderen van add-ons. Het vereist een geldige Access Token
 * om toegang te krijgen tot de TransIP API en maakt gebruik van cookies en sessies
 * om gegevens tijdelijk op te slaan.
 *
 * Copyright (c) 2026 TransIP BV. Alle rechten voorbehouden.
 *
 * DISCLAIMER:
 * Dit script is uitsluitend bedoeld als voorbeeld. TransIP BV biedt dit script
 * aan "zoals het is" en is niet aansprakelijk voor eventuele fouten, defecten
 * of andere problemen die voortvloeien uit het gebruik van dit script.
 * Het gebruik van dit script is op eigen risico.
 */

const ATM_API_BASE = 'https://api.transip.nl/v6/';
const ATM_TOKEN_MAX_LENGTH = 4096;
const ATM_MUTATION_COOLDOWN_SECONDS = 5;
const ATM_MAX_SUBMIT_TOKENS = 10;

const ATM_PRODUCTS = [
    'acronis-50gb' => '50 GB',
    'acronis-100gb' => '100 GB',
    'acronis-250gb' => '250 GB',
    'acronis-500gb' => '500 GB',
    'acronis-1000gb' => '1 TB',
    'acronis-2000gb' => '2 TB',
];

const ATM_ADDONS = [
    'acronisAddon-250gb' => '250 GB Storage Add-on',
    'acronisAddon-edr' => 'Acronis EDR',
];

/**
 * Controleert of de huidige aanvraag via HTTPS of vanaf localhost komt.
 *
 * Een Access Token mag alleen via een versleutelde verbinding worden ingevoerd.
 * Localhost blijft toegestaan, zodat het voorbeeld lokaal kan worden getest.
 *
 * @return bool True bij HTTPS of een lokaal hostadres, anders false.
 */
function atm_request_is_secure(): bool
{
    $https = strtolower((string) ($_SERVER['HTTPS'] ?? ''));
    if ($https !== '' && $https !== 'off') {
        return true;
    }

    $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
    $host = preg_replace('/:\d+$/', '', $host) ?? '';

    return in_array($host, ['localhost', '127.0.0.1', '[::1]', '::1'], true);
}

/**
 * Start de PHP-sessie met veilige cookie-instellingen.
 *
 * De browsercookie bevat uitsluitend de willekeurige PHP-sessie-ID. Het Access
 * Token zelf wordt server-side in de sessie bewaard en nooit in de cookie gezet.
 *
 * @return void
 */
function atm_start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_trans_sid', '0');
    session_name('acronis_tenant_manager');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => atm_request_is_secure(),
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_start();
}

/**
 * Maakt een waarde veilig voor uitvoer in HTML.
 *
 * Gebruik deze helper voor alle gegevens die uit de API, sessie of gebruiker
 * afkomstig zijn voordat ze in de pagina worden weergegeven.
 *
 * @param mixed $value De waarde die als tekst in HTML wordt geplaatst.
 * @return string De ge-escapete UTF-8-tekst.
 */
function atm_h(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Begrens een tekst tot een maximaal aantal tekens.
 *
 * Wanneer mbstring beschikbaar is, worden UTF-8-tekens correct afgekapt. De
 * fallback houdt het script bruikbaar op een minimale PHP-installatie.
 *
 * @param string $value De tekst die moet worden begrensd.
 * @param int $length Het maximale aantal tekens of bytes.
 * @return string De begrensde tekst.
 */
function atm_truncate(string $value, int $length): string
{
    if (function_exists('mb_substr')) {
        return mb_substr($value, 0, $length, 'UTF-8');
    }

    return substr($value, 0, $length);
}

/**
 * Bepaalt een veilig lokaal redirectpad voor Post/Redirect/Get.
 *
 * Regelafbrekingen en onverwachte relatieve waarden worden geweigerd om
 * headerinjectie en redirects naar een ander adres te voorkomen.
 *
 * @return string Het absolute pad van dit script binnen de huidige website.
 */
function atm_script_path(): string
{
    $path = (string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php');
    if (
        $path === ''
        || $path[0] !== '/'
        || str_starts_with($path, '//')
        || str_contains($path, '\\')
        || preg_match('/[\r\n]/', $path) === 1
    ) {
        return '/index.php';
    }

    return $path;
}

/**
 * Bewaart één korte melding voor de eerstvolgende GET-aanvraag.
 *
 * De melding wordt server-side in de sessie opgeslagen. Alleen vaste melding-
 * typen worden geaccepteerd en de tekst wordt in lengte begrensd.
 *
 * @param string $message De gebruikersmelding.
 * @param string $type Het meldingtype: info, success of error.
 * @return void
 */
function atm_flash(string $message, string $type = 'info'): void
{
    $allowedTypes = ['info', 'success', 'error'];
    $_SESSION['atm_flash'] = [
        'message' => atm_truncate($message, 500),
        'type' => in_array($type, $allowedTypes, true) ? $type : 'info',
    ];
}

/**
 * Leest de eenmalige melding en verwijdert deze direct uit de sessie.
 *
 * @return array{message: string, type: string}|null De melding, of null wanneer
 *     geen geldige melding beschikbaar is.
 */
function atm_take_flash(): ?array
{
    $flash = $_SESSION['atm_flash'] ?? null;
    unset($_SESSION['atm_flash']);

    if (!is_array($flash) || !is_string($flash['message'] ?? null) || !is_string($flash['type'] ?? null)) {
        return null;
    }

    return $flash;
}

/**
 * Rondt een POST-aanvraag af met een HTTP 303-redirect.
 *
 * Hierdoor komt een schone GET in de browsergeschiedenis en wordt dezelfde
 * mutatie niet opnieuw verstuurd wanneer de gebruiker de pagina ververst.
 *
 * @return void Deze functie beëindigt het script na de redirect.
 */
function atm_redirect(): void
{
    header('Location: ' . atm_script_path(), true, 303);
    exit;
}

/**
 * Genereert of leest het CSRF-token van de huidige sessie.
 *
 * @return string Het cryptografisch willekeurige CSRF-token.
 */
function atm_csrf_token(): string
{
    if (!is_string($_SESSION['atm_csrf_token'] ?? null)) {
        $_SESSION['atm_csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['atm_csrf_token'];
}

/**
 * Vergelijkt een aangeboden CSRF-token tijdsconstant met het sessietoken.
 *
 * @param mixed $token De waarde uit het POST-formulier.
 * @return bool True wanneer het token geldig is.
 */
function atm_csrf_is_valid(mixed $token): bool
{
    return is_string($token)
        && is_string($_SESSION['atm_csrf_token'] ?? null)
        && hash_equals($_SESSION['atm_csrf_token'], $token);
}

/**
 * Maakt een eenmalig token voor een muterend formulier.
 *
 * Per sessie worden maximaal ATM_MAX_SUBMIT_TOKENS tokens bewaard. Dit beperkt
 * sessiegroei en voorkomt dat één formulier tweemaal een API-mutatie uitvoert.
 *
 * @return string Het nieuwe eenmalige formuliertoken.
 */
function atm_issue_submit_token(): string
{
    $token = bin2hex(random_bytes(32));
    $tokens = is_array($_SESSION['atm_submit_tokens'] ?? null)
        ? $_SESSION['atm_submit_tokens']
        : [];
    $tokens[$token] = time();
    $_SESSION['atm_submit_tokens'] = array_slice($tokens, -ATM_MAX_SUBMIT_TOKENS, null, true);

    return $token;
}

/**
 * Controleert en verbruikt een eenmalig formuliertoken.
 *
 * Het token wordt vóór de externe API-mutatie verwijderd. Een dubbele klik of
 * handmatig herhaalde POST kan daardoor niet dezelfde mutatie opnieuw starten.
 *
 * @param mixed $token De waarde uit het POST-formulier.
 * @return bool True wanneer het token bestond en nu is verbruikt.
 */
function atm_consume_submit_token(mixed $token): bool
{
    if (!is_string($token) || !isset($_SESSION['atm_submit_tokens'][$token])) {
        return false;
    }

    unset($_SESSION['atm_submit_tokens'][$token]);
    return true;
}

/**
 * Valideert een Access Token voordat het in de server-side sessie komt.
 *
 * Het token wordt alleen gecontroleerd op type, lengte en besturingskarakters.
 * De geldigheid bij TransIP blijkt pas uit een daaropvolgende API-aanroep.
 *
 * @param mixed $value De aangeboden tokenwaarde.
 * @return string|null Het opgeschoonde token, of null bij ongeldige invoer.
 */
function atm_validate_access_token(mixed $value): ?string
{
    if (!is_string($value)) {
        return null;
    }

    $token = trim($value);
    if (
        $token === ''
        || strlen($token) > ATM_TOKEN_MAX_LENGTH
        || preg_match('/[\x00-\x1F\x7F]/', $token) === 1
    ) {
        return null;
    }

    return $token;
}

/**
 * Wist uitsluitend de sessiegegevens van dit voorbeeldscript.
 *
 * @param bool $includeToken Verwijder ook het Access Token wanneer true.
 * @return void
 */
function atm_clear_application_state(bool $includeToken = true): void
{
    $keys = [
        'atm_access_token',
        'atm_selected_tenant_uuid',
        'atm_submit_tokens',
        'atm_mutation_guard',
        'atm_flash',
    ];

    foreach ($keys as $key) {
        if ($includeToken || $key !== 'atm_access_token') {
            unset($_SESSION[$key]);
        }
    }
}

/**
 * Voert één TransIP API-aanroep uit.
 *
 * De responsebody wordt alleen geparseerd bij een succesvolle status. Bij een
 * fout gaat uitsluitend een generieke status terug naar de interface. Het
 * Access Token en de volledige upstreamresponse worden nooit gerenderd.
 *
 * @param string $path Het API-pad relatief aan ATM_API_BASE.
 * @param string $method De HTTP-methode, bijvoorbeeld GET, POST, PUT of DELETE.
 * @param string $accessToken De TransIP Access Token uit de server-side sessie.
 * @param array<string, mixed>|null $payload Optionele gegevens voor de JSON-body.
 * @return array{ok: bool, status: int, data: array<string, mixed>}
 *     Het resultaat, de HTTP-status en de geparseerde succesvolle response.
 */
function atm_api_request(string $path, string $method, string $accessToken, ?array $payload = null): array
{
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'status' => 0, 'data' => []];
    }

    $handle = curl_init(ATM_API_BASE . ltrim($path, '/'));
    if ($handle === false) {
        return ['ok' => false, 'status' => 0, 'data' => []];
    }

    $headers = [
        'Authorization: Bearer ' . $accessToken,
        'Accept: application/json',
        'Content-Type: application/json',
    ];

    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 20,
    ]);

    if ($payload !== null) {
        try {
            $json = json_encode($payload, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            curl_close($handle);
            return ['ok' => false, 'status' => 0, 'data' => []];
        }
        curl_setopt($handle, CURLOPT_POSTFIELDS, $json);
    }

    $response = curl_exec($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
    $curlFailed = $response === false;
    curl_close($handle);

    if ($curlFailed || !in_array($status, [200, 201, 202, 204], true)) {
        return ['ok' => false, 'status' => $status, 'data' => []];
    }

    if (!is_string($response) || trim($response) === '') {
        return ['ok' => true, 'status' => $status, 'data' => []];
    }

    try {
        $decoded = json_decode($response, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        return ['ok' => false, 'status' => $status, 'data' => []];
    }

    return [
        'ok' => is_array($decoded),
        'status' => $status,
        'data' => is_array($decoded) ? $decoded : [],
    ];
}

/**
 * Controleert het UUID-formaat voordat een waarde in een API-pad komt.
 *
 * @param string $uuid De te controleren tenant-UUID.
 * @return bool True wanneer de waarde een volledig UUID-formaat heeft.
 */
function atm_uuid_is_valid(string $uuid): bool
{
    return preg_match(
        '/^[0-9a-f]{8}-(?:[0-9a-f]{4}-){3}[0-9a-f]{12}$/i',
        $uuid
    ) === 1;
}

/**
 * Haalt de tenants op en normaliseert alleen de velden die de interface gebruikt.
 *
 * Ongeldige records worden overgeslagen. De overgebleven tenants worden
 * natuurlijk en hoofdletterongevoelig op naam gesorteerd.
 *
 * @param string $accessToken De TransIP Access Token uit de sessie.
 * @return array{ok: bool, tenants: array<int, array{uuid: string, name: string, storage: int, edr: int}>}
 *     De genormaliseerde tenantlijst en een status die aangeeft of de API-call
 *     als geheel is geslaagd.
 */
function atm_fetch_tenants(string $accessToken): array
{
    $result = atm_api_request('acronis/tenants', 'GET', $accessToken);
    $rawTenants = $result['data']['tenants'] ?? null;
    if (!$result['ok'] || !is_array($rawTenants)) {
        return ['ok' => false, 'tenants' => []];
    }

    $tenants = [];
    foreach ($rawTenants as $tenant) {
        if (!is_array($tenant)) {
            continue;
        }

        $uuid = is_string($tenant['uuid'] ?? null) ? $tenant['uuid'] : '';
        $name = is_string($tenant['name'] ?? null) ? trim($tenant['name']) : '';
        if (!atm_uuid_is_valid($uuid) || $name === '') {
            continue;
        }

        $tenants[] = [
            'uuid' => $uuid,
            'name' => atm_truncate($name, 200),
            'storage' => max(0, (int) ($tenant['storageSizeInGB'] ?? 0)),
            'edr' => max(0, (int) ($tenant['edrEndpoints'] ?? 0)),
        ];
    }

    usort(
        $tenants,
        static fn (array $left, array $right): int => strnatcasecmp($left['name'], $right['name'])
    );

    return ['ok' => true, 'tenants' => $tenants];
}

/**
 * Haalt het actuele opslaggebruik van één tenant op.
 *
 * Niet-numerieke, ontbrekende of negatieve waarden worden niet als nul getoond,
 * maar resulteren in een onbekende usage-status.
 *
 * @param string $tenantUuid De gevalideerde UUID van de tenant.
 * @param string $accessToken De TransIP Access Token uit de sessie.
 * @return array{ok: bool, current: ?float, limit: ?float}
 *     Het actuele gebruik en de limiet in GB, of null-waarden bij een fout.
 */
function atm_fetch_usage(string $tenantUuid, string $accessToken): array
{
    $result = atm_api_request(
        'acronis/tenants/' . rawurlencode($tenantUuid) . '/usage',
        'GET',
        $accessToken
    );
    $usage = $result['data']['usage'] ?? null;
    if (!$result['ok'] || !is_array($usage)) {
        return ['ok' => false, 'current' => null, 'limit' => null];
    }

    $current = is_numeric($usage['currentUsage'] ?? null) ? (float) $usage['currentUsage'] : null;
    $limit = is_numeric($usage['limit'] ?? null) ? (float) $usage['limit'] : null;
    if ($current === null || $limit === null || $current < 0 || $limit < 0) {
        return ['ok' => false, 'current' => null, 'limit' => null];
    }

    return ['ok' => true, 'current' => $current, 'limit' => $limit];
}

/**
 * Vindt een tenant uitsluitend in de actuele server-side tenantlijst.
 *
 * Deze controle voorkomt dat een door de browser gemanipuleerde UUID rechtstreeks
 * voor een muterende API-aanroep wordt gebruikt.
 *
 * @param array<int, array<string, mixed>> $tenants De actuele tenantlijst.
 * @param string $tenantUuid De UUID die moet worden gevonden.
 * @return array<string, mixed>|null De gevonden tenant, of null bij geen match.
 */
function atm_find_tenant(array $tenants, string $tenantUuid): ?array
{
    foreach ($tenants as $tenant) {
        if (is_array($tenant) && hash_equals((string) $tenant['uuid'], $tenantUuid)) {
            return $tenant;
        }
    }

    return null;
}

/**
 * Bepaalt de resterende lokale wachttijd voor één tenant.
 *
 * Verlopen en ongeldige entries worden tijdens het lezen opgeruimd. De blokkade
 * is per browsersessie en vervangt geen upstream-lock of transactieverwerking.
 *
 * @param string $tenantUuid De UUID van de tenant.
 * @param int|null $now Een optioneel tijdstip voor deterministische tests.
 * @return int Het resterende aantal hele seconden, of 0 zonder blokkade.
 */
function atm_cooldown_remaining(string $tenantUuid, ?int $now = null): int
{
    $now ??= time();
    $entries = is_array($_SESSION['atm_mutation_guard'] ?? null)
        ? $_SESSION['atm_mutation_guard']
        : [];
    $valid = [];

    foreach (array_slice($entries, -100, null, true) as $uuid => $timestamp) {
        if (!is_string($uuid) || !is_int($timestamp)) {
            continue;
        }
        if ($timestamp + ATM_MUTATION_COOLDOWN_SECONDS > $now) {
            $valid[$uuid] = $timestamp;
        }
    }

    if ($valid === []) {
        unset($_SESSION['atm_mutation_guard']);
    } else {
        $_SESSION['atm_mutation_guard'] = $valid;
    }

    if (!isset($valid[$tenantUuid])) {
        return 0;
    }

    return max(1, $valid[$tenantUuid] + ATM_MUTATION_COOLDOWN_SECONDS - $now);
}

/**
 * Registreert het startpunt van de korte lokale mutation-cooldown.
 *
 * @param string $tenantUuid De UUID van de gewijzigde tenant.
 * @param int|null $now Een optioneel tijdstip voor deterministische tests.
 * @return void
 */
function atm_register_cooldown(string $tenantUuid, ?int $now = null): void
{
    atm_cooldown_remaining($tenantUuid, $now);
    $entries = is_array($_SESSION['atm_mutation_guard'] ?? null)
        ? $_SESSION['atm_mutation_guard']
        : [];
    $entries[$tenantUuid] = $now ?? time();
    $_SESSION['atm_mutation_guard'] = array_slice($entries, -100, null, true);
}

/**
 * Wijzigt het pakket van een tenant.
 *
 * Zowel een groter als kleiner toegestaan pakket gebruikt de door de TransIP
 * API gedocumenteerde upgrades-route.
 *
 * @param string $tenantUuid De gevalideerde UUID van de tenant.
 * @param string $productName Een productnaam uit ATM_PRODUCTS.
 * @param string $accessToken De TransIP Access Token uit de sessie.
 * @return array{ok: bool, status: int, data: array<string, mixed>} De API-response.
 */
function atm_change_package(string $tenantUuid, string $productName, string $accessToken): array
{
    return atm_api_request(
        'acronis/tenants/' . rawurlencode($tenantUuid) . '/upgrades',
        'PUT',
        $accessToken,
        ['productName' => $productName]
    );
}

/**
 * Voegt één toegestane add-on toe aan een tenant.
 *
 * @param string $tenantUuid De gevalideerde UUID van de tenant.
 * @param string $addonName Een add-onnaam uit ATM_ADDONS.
 * @param string $accessToken De TransIP Access Token uit de sessie.
 * @return array{ok: bool, status: int, data: array<string, mixed>} De API-response.
 */
function atm_add_addon(string $tenantUuid, string $addonName, string $accessToken): array
{
    return atm_api_request(
        'acronis/tenants/' . rawurlencode($tenantUuid) . '/addons',
        'POST',
        $accessToken,
        ['addons' => [$addonName]]
    );
}

/**
 * Verwijdert één toegestane add-on van een tenant.
 *
 * @param string $tenantUuid De gevalideerde UUID van de tenant.
 * @param string $addonName Een add-onnaam uit ATM_ADDONS.
 * @param string $accessToken De TransIP Access Token uit de sessie.
 * @return array{ok: bool, status: int, data: array<string, mixed>} De API-response.
 */
function atm_remove_addon(string $tenantUuid, string $addonName, string $accessToken): array
{
    return atm_api_request(
        'acronis/tenants/' . rawurlencode($tenantUuid) . '/addons/' . rawurlencode($addonName),
        'DELETE',
        $accessToken
    );
}

/*
 * Initialiseer de sessie en beveiligingsheaders voordat HTML wordt verstuurd.
 * Omdat de pagina een tijdelijk Access Token en muterende formulieren bevat,
 * mag een browser of tussenliggende proxy de response niet opslaan.
 */
atm_start_session();

/*
 * Sta uitsluitend dit ene, server-side gemarkeerde inline script toe. De nonce
 * is per response willekeurig en houdt het voorbeeldscript in één PHP-bestand,
 * zonder de Content-Security-Policy voor andere inline scripts te versoepelen.
 */
$cspNonce = base64_encode(random_bytes(16));

header('Cache-Control: no-store, max-age=0');
header('Pragma: no-cache');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header(
    "Content-Security-Policy: default-src 'self'; "
    . "script-src 'nonce-{$cspNonce}'; "
    . "style-src 'unsafe-inline'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'"
);

$method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');
if (!in_array($method, ['GET', 'POST'], true)) {
    http_response_code(405);
    header('Allow: GET, POST');
    exit('Methode niet toegestaan.');
}

/*
 * Verwerk alle formulieracties server-side. Iedere codepad eindigt via een
 * HTTP 303-redirect, zodat een pagina-refresh nooit dezelfde POST herhaalt.
 */
if ($method === 'POST') {
    // Controleer CSRF voordat een actie of andere formulierwaarde wordt gebruikt.
    if (!atm_csrf_is_valid($_POST['csrf_token'] ?? null)) {
        atm_flash('De pagina was verouderd. Ververs de pagina en probeer opnieuw.', 'error');
        atm_redirect();
    }

    $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';

    /*
     * Sla een nieuw Access Token alleen op via HTTPS. Na tokenvervanging wordt
     * de sessie-ID vernieuwd en worden oude tenant- en formuliergegevens gewist.
     */
    if ($action === 'setToken') {
        if (!atm_request_is_secure()) {
            atm_flash('Gebruik HTTPS voordat je een Access Token invoert.', 'error');
            atm_redirect();
        }

        $token = atm_validate_access_token($_POST['accessToken'] ?? null);
        if ($token === null) {
            atm_flash('Voer een geldig Access Token in.', 'error');
            atm_redirect();
        }

        atm_clear_application_state();
        unset($_SESSION['atm_csrf_token']);
        session_regenerate_id(true);
        $_SESSION['atm_access_token'] = $token;
        atm_flash('Het Access Token is veilig opgeslagen voor deze browsersessie.', 'success');
        atm_redirect();
    }

    // Reset alleen de gegevens van dit script en vernieuw daarna de sessie-ID.
    if ($action === 'resetToken') {
        atm_clear_application_state();
        unset($_SESSION['atm_csrf_token']);
        session_regenerate_id(true);
        atm_flash('Het Access Token en de tijdelijke sessiegegevens zijn verwijderd.', 'success');
        atm_redirect();
    }

    /*
     * Alle overige acties vereisen een geldig server-side Access Token. De
     * tenantlijst wordt live opgehaald, zodat een geposte UUID nooit zelfstandig
     * als autorisatie voor een wijziging geldt.
     */
    $accessToken = atm_validate_access_token($_SESSION['atm_access_token'] ?? null);
    if ($accessToken === null) {
        atm_clear_application_state();
        atm_flash('Stel eerst een geldig Access Token in.', 'error');
        atm_redirect();
    }

    $tenantResult = atm_fetch_tenants($accessToken);
    if (!$tenantResult['ok']) {
        atm_flash('De tenantlijst kon niet worden opgehaald. Controleer het Access Token en de API-whitelist.', 'error');
        atm_redirect();
    }

    $tenantUuid = is_string($_POST['tenantUuid'] ?? null) ? $_POST['tenantUuid'] : '';
    $tenant = atm_find_tenant($tenantResult['tenants'], $tenantUuid);
    if ($tenant === null) {
        atm_flash('De geselecteerde tenant is niet geldig.', 'error');
        atm_redirect();
    }

    if ($action === 'selectTenant') {
        $_SESSION['atm_selected_tenant_uuid'] = $tenant['uuid'];
        atm_flash('Tenant succesvol gewijzigd.', 'success');
        atm_redirect();
    }

    // Alleen de drie hieronder genoemde acties mogen een tenant wijzigen.
    if (!in_array($action, ['changePackage', 'addAddon', 'removeAddon'], true)) {
        atm_flash('Ongeldige actie.', 'error');
        atm_redirect();
    }

    /*
     * Verbruik het eenmalige token vóór de externe mutatie. Een dubbele klik,
     * terugknop of handmatig opnieuw verzonden formulier kan zo geen tweede
     * upstreamaanvraag starten.
     */
    if (!atm_consume_submit_token($_POST['submit_token'] ?? null)) {
        atm_flash('Dit formulier is al gebruikt of verouderd. Ververs de pagina en probeer opnieuw.', 'error');
        atm_redirect();
    }

    // Blokkeer kort een tweede wijziging voor dezelfde tenant en browsersessie.
    $remaining = atm_cooldown_remaining($tenant['uuid']);
    if ($remaining > 0) {
        atm_flash("Voor deze tenant is mogelijk nog een wijziging in verwerking. Probeer over {$remaining} seconden opnieuw.", 'error');
        atm_redirect();
    }

    /*
     * Controleer producten en add-ons uitsluitend tegen vaste server-side
     * allowlists. De storage-add-on vereist bovendien een actuele limiet van
     * minimaal 2 TB; EDR heeft die opslagvoorwaarde niet.
     */
    if ($action === 'changePackage') {
        $productName = is_string($_POST['productName'] ?? null) ? $_POST['productName'] : '';
        if (!array_key_exists($productName, ATM_PRODUCTS)) {
            atm_flash('Het geselecteerde pakket is niet geldig.', 'error');
            atm_redirect();
        }
    } else {
        $addonName = is_string($_POST['addonName'] ?? null) ? $_POST['addonName'] : '';
        if (!array_key_exists($addonName, ATM_ADDONS)) {
            atm_flash('De geselecteerde add-on is niet geldig.', 'error');
            atm_redirect();
        }

        if ($action === 'addAddon' && $addonName === 'acronisAddon-250gb') {
            $usage = atm_fetch_usage($tenant['uuid'], $accessToken);
            if (!$usage['ok'] || $usage['limit'] === null || $usage['limit'] < 2000) {
                atm_flash('De storage-add-on kan alleen worden toegevoegd aan een tenant met minimaal 2 TB.', 'error');
                atm_redirect();
            }
        }
    }

    // Leg de korte blokkade pas vast nadat alle invoer is gevalideerd, maar
    // voordat de externe mutatie wordt gestart.
    atm_register_cooldown($tenant['uuid']);

    if ($action === 'changePackage') {
        $result = atm_change_package($tenant['uuid'], $productName, $accessToken);
    } else {
        $result = $action === 'addAddon'
            ? atm_add_addon($tenant['uuid'], $addonName, $accessToken)
            : atm_remove_addon($tenant['uuid'], $addonName, $accessToken);
    }

    // Laat de wachttijd opnieuw ingaan nadat de upstreamaanvraag is afgerond.
    atm_register_cooldown($tenant['uuid']);

    if ($result['ok']) {
        atm_flash(
            'De wijzigingsaanvraag is geaccepteerd. Wacht tot de tenantgegevens zijn bijgewerkt voordat je een volgende wijziging uitvoert.',
            'success'
        );
    } else {
        atm_flash('De wijziging kon momenteel niet worden uitgevoerd. Controleer de actuele tenantstatus en probeer het later opnieuw.', 'error');
    }
    atm_redirect();
}

/*
 * Bereid de GET-weergave voor. Er wordt één tenantlijstrequest uitgevoerd en
 * alleen voor de geselecteerde tenant een usage-request. Daardoor ontstaat bij
 * veel tenants geen afzonderlijke usage-call voor iedere dropdownoptie.
 */
$flash = atm_take_flash();
$csrfToken = atm_csrf_token();
$accessToken = atm_validate_access_token($_SESSION['atm_access_token'] ?? null);
$tenantLoadError = false;
$tenants = [];
$selectedTenant = null;
$selectedUsage = ['ok' => false, 'current' => null, 'limit' => null];

if ($accessToken !== null) {
    $tenantResult = atm_fetch_tenants($accessToken);
    if (!$tenantResult['ok']) {
        $tenantLoadError = true;
    } else {
        $tenants = $tenantResult['tenants'];
        $selectedUuid = is_string($_SESSION['atm_selected_tenant_uuid'] ?? null)
            ? $_SESSION['atm_selected_tenant_uuid']
            : '';
        $selectedTenant = atm_find_tenant($tenants, $selectedUuid);

        if ($selectedTenant === null && isset($tenants[0])) {
            $selectedTenant = $tenants[0];
            $_SESSION['atm_selected_tenant_uuid'] = $selectedTenant['uuid'];
        }

        if ($selectedTenant !== null) {
            $selectedUsage = atm_fetch_usage($selectedTenant['uuid'], $accessToken);
        }
    }
}

$cooldownRemaining = $selectedTenant !== null
    ? atm_cooldown_remaining($selectedTenant['uuid'])
    : 0;
$mutationsDisabled = $cooldownRemaining > 0;
$packageSubmitToken = $selectedTenant !== null ? atm_issue_submit_token() : '';
$addonSubmitToken = $selectedTenant !== null ? atm_issue_submit_token() : '';

$currentStorage = (int) ($selectedTenant['storage'] ?? 0);
$selectedProduct = 'acronis-50gb';
foreach (array_keys(ATM_PRODUCTS) as $productKey) {
    if (preg_match('/acronis-(\d+)gb/', $productKey, $matches) === 1 && (int) $matches[1] <= min($currentStorage, 2000)) {
        $selectedProduct = $productKey;
    }
}

$usagePercent = null;
if (
    $selectedUsage['ok']
    && $selectedUsage['current'] !== null
    && $selectedUsage['limit'] !== null
    && $selectedUsage['limit'] > 0
) {
    $usagePercent = min(100.0, max(0.0, ($selectedUsage['current'] / $selectedUsage['limit']) * 100));
}

/**
 * Formatteert een opslagwaarde voor Nederlandstalige weergave.
 *
 * @param float|null $value De waarde in GB, of null wanneer deze onbekend is.
 * @return string De geformatteerde waarde met GB-eenheid.
 */
function atm_format_gb(?float $value): string
{
    if ($value === null) {
        return 'Onbekend';
    }

    $decimals = floor($value) === $value ? 0 : 2;
    return number_format($value, $decimals, ',', '.') . ' GB';
}
?>
<!doctype html>
<html lang="nl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Acronis Tenant Manager</title>
    <style>
        :root {
            color-scheme: light;
            --blue: #073b70;
            --blue-dark: #052e58;
            --blue-soft: #eaf4ff;
            --blue-border: #87b9eb;
            --bg: #f3f7fb;
            --panel: #fff;
            --text: #142033;
            --muted: #5d6b7e;
            --border: #cfd9e5;
            --success: #18743a;
            --success-bg: #ebf8ef;
            --error: #a3231b;
            --error-bg: #fff0ef;
            --warning: #8a5a00;
            --warning-bg: #fff7dc;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            background: var(--bg);
            color: var(--text);
            font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            line-height: 1.45;
        }
        .topbar { background: var(--blue); color: #fff; }
        .topbar-inner,
        .page { width: min(1120px, calc(100% - 32px)); margin: 0 auto; }
        .topbar-inner {
            min-height: 82px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
        }
        .eyebrow { font-size: .76rem; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; opacity: .8; }
        .brand { margin: 2px 0 0; font-size: 1.55rem; }
        .badge {
            display: inline-flex;
            align-items: center;
            border-radius: 999px;
            padding: 5px 10px;
            background: #ffd541;
            color: #332600;
            font-size: .78rem;
            font-weight: 800;
        }
        .page { padding: 34px 0 56px; }
        .intro { display: flex; justify-content: space-between; gap: 20px; align-items: start; margin-bottom: 20px; }
        h1 { margin: 0 0 5px; font-size: clamp(1.6rem, 3vw, 2.2rem); }
        h2 { margin: 0 0 12px; font-size: 1.25rem; }
        p { margin: 0 0 12px; }
        .muted { color: var(--muted); }
        .panel {
            background: var(--panel);
            border: 1px solid var(--border);
            border-radius: 12px;
            box-shadow: 0 2px 5px rgba(20, 32, 51, .04);
            padding: 20px;
            margin-bottom: 16px;
        }
        .panel-head { display: flex; align-items: start; justify-content: space-between; gap: 18px; }
        .notice {
            border: 1px solid var(--blue-border);
            border-left: 5px solid #0874c9;
            border-radius: 9px;
            background: var(--blue-soft);
            padding: 12px 14px;
            margin-bottom: 16px;
        }
        .notice.success { border-color: #8ed1a1; border-left-color: var(--success); background: var(--success-bg); }
        .notice.error { border-color: #efaaa5; border-left-color: var(--error); background: var(--error-bg); }
        .notice.warning { border-color: #e5ca6c; border-left-color: var(--warning); background: var(--warning-bg); }
        .notice strong { display: block; margin-bottom: 2px; }
        .grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 16px; }
        .stats { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 10px; margin-top: 18px; }
        .stat { background: #f1f6fb; border-radius: 8px; padding: 12px; min-width: 0; }
        .stat span { display: block; color: var(--muted); font-size: .8rem; }
        .stat strong { display: block; margin-top: 3px; overflow-wrap: anywhere; }
        label { display: block; font-weight: 700; margin: 0 0 6px; }
        input, select, button { font: inherit; }
        input[type="password"], select {
            width: 100%;
            min-height: 42px;
            border: 1px solid #9eb0c4;
            border-radius: 7px;
            background: #fff;
            color: var(--text);
            padding: 8px 10px;
        }
        .actions { display: flex; flex-wrap: wrap; gap: 9px; margin-top: 12px; }
        button, .button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 40px;
            border: 1px solid var(--blue);
            border-radius: 7px;
            background: var(--blue);
            color: #fff;
            text-decoration: none;
            font-weight: 700;
            padding: 8px 13px;
            cursor: pointer;
        }
        button:hover, .button:hover { background: var(--blue-dark); }
        button.secondary { background: #fff; color: var(--text); border-color: #9eb0c4; }
        button.secondary:hover { background: #edf3f8; }
        button:disabled { cursor: not-allowed; opacity: .55; }
        button:focus-visible, input:focus-visible, select:focus-visible, a:focus-visible {
            outline: 3px solid #63aaf1;
            outline-offset: 2px;
        }
        .tenant-id { color: var(--muted); overflow-wrap: anywhere; }
        .progress { margin-top: 16px; }
        .progress-label { display: flex; justify-content: space-between; gap: 12px; margin-bottom: 5px; }
        .progress-track { height: 12px; overflow: hidden; border-radius: 999px; background: #dbe5ee; }
        .progress-bar { height: 100%; background: #0874c9; }
        .help { font-size: .9rem; color: var(--muted); }
        .help a { color: #075ca8; }
        .no-margin { margin: 0; }
        @media (max-width: 780px) {
            .grid, .stats { grid-template-columns: 1fr; }
            .topbar-inner, .intro, .panel-head { align-items: stretch; flex-direction: column; }
            .page, .topbar-inner { width: min(100% - 20px, 1120px); }
            .topbar-inner { padding: 16px 0; }
        }
        @media (prefers-reduced-motion: reduce) {
            *, *::before, *::after { scroll-behavior: auto !important; transition: none !important; }
        }
    </style>
</head>
<body>
<header class="topbar">
    <div class="topbar-inner">
        <div>
            <div class="eyebrow">PHP-voorbeeldscript</div>
            <div class="brand">Acronis Tenant Manager</div>
        </div>
        <span class="badge">Gebruik op eigen verantwoordelijkheid</span>
    </div>
</header>

<main class="page">
    <div class="intro">
        <div>
            <h1>Acronis-tenants beheren</h1>
            <p class="muted">Bekijk een tenant en dien pakket- of add-onwijzigingen in via de TransIP API.</p>
        </div>
        <?php if ($accessToken !== null): ?>
            <form method="post" class="no-margin">
                <input type="hidden" name="csrf_token" value="<?= atm_h($csrfToken) ?>">
                <input type="hidden" name="action" value="resetToken">
                <button type="submit" class="secondary">Access Token verwijderen</button>
            </form>
        <?php endif; ?>
    </div>

    <?php if ($flash !== null): ?>
        <div class="notice <?= atm_h($flash['type']) ?>" role="<?= $flash['type'] === 'error' ? 'alert' : 'status' ?>">
            <strong><?= $flash['type'] === 'error' ? 'Let op' : 'Info' ?></strong>
            <?= atm_h($flash['message']) ?>
        </div>
    <?php endif; ?>

    <?php if ($accessToken === null): ?>
        <section class="panel" aria-labelledby="token-heading">
            <h2 id="token-heading">Access Token instellen</h2>
            <p>
                Om dit script te gebruiken, heb je een Access Token nodig.
                <a href="https://www.transip.nl/knowledgebase/api/77-de-transip-rest-api-gebruiken#De-API-inschakelen-toegang-en-whitelisting" target="_blank" rel="noopener noreferrer">Lees hoe je de API activeert en een Access Token aanmaakt</a>.
            </p>
            <p class="help">Gebruik bij voorkeur een tijdelijk token, open dit script alleen via HTTPS en trek het token na gebruik weer in.</p>

            <?php if (!atm_request_is_secure()): ?>
                <div class="notice error" role="alert">
                    <strong>HTTPS vereist</strong>
                    De tokeninvoer is geblokkeerd omdat deze pagina niet via HTTPS wordt geopend.
                </div>
            <?php endif; ?>

            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= atm_h($csrfToken) ?>">
                <input type="hidden" name="action" value="setToken">
                <label for="accessToken">Access Token</label>
                <input
                    type="password"
                    id="accessToken"
                    name="accessToken"
                    maxlength="<?= ATM_TOKEN_MAX_LENGTH ?>"
                    autocomplete="new-password"
                    required
                    <?= !atm_request_is_secure() ? 'disabled' : '' ?>
                >
                <div class="actions">
                    <button type="submit" <?= !atm_request_is_secure() ? 'disabled' : '' ?>>Token opslaan voor deze sessie</button>
                </div>
            </form>
        </section>
    <?php elseif ($tenantLoadError): ?>
        <div class="notice error" role="alert">
            <strong>Tenantgegevens niet beschikbaar</strong>
            De tenantlijst kon niet worden opgehaald. Controleer het Access Token en de ingestelde API-whitelist.
        </div>
    <?php elseif ($tenants === []): ?>
        <div class="notice warning" role="status">
            <strong>Geen tenants gevonden</strong>
            De API heeft geen geldige Acronis-tenants teruggegeven.
        </div>
    <?php else: ?>
        <section class="panel" aria-labelledby="tenant-select-heading">
            <div class="panel-head">
                <div>
                    <h2 id="tenant-select-heading">Selecteer tenant</h2>
                    <p class="help">De lijst toont pakketgrootte en EDR-aantal uit het actuele tenantoverzicht. Gebruik en limiet worden alleen voor de geselecteerde tenant opgehaald.</p>
                </div>
                <form method="get">
                    <button type="submit" class="secondary">Gegevens vernieuwen</button>
                </form>
            </div>
            <form method="post" id="tenant-selection-form">
                <input type="hidden" name="csrf_token" value="<?= atm_h($csrfToken) ?>">
                <input type="hidden" name="action" value="selectTenant">
                <label for="tenantUuid">Tenant</label>
                <select id="tenantUuid" name="tenantUuid" data-tenant-selection>
                    <?php foreach ($tenants as $tenant): ?>
                        <option value="<?= atm_h($tenant['uuid']) ?>" <?= $selectedTenant !== null && hash_equals($selectedTenant['uuid'], $tenant['uuid']) ? 'selected' : '' ?>>
                            <?= atm_h($tenant['name']) ?> — pakket <?= atm_h($tenant['storage']) ?> GB — EDR <?= atm_h($tenant['edr']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <p class="help">Na het kiezen worden de gegevens van die tenant direct geladen.</p>
                <noscript>
                    <div class="actions">
                        <button type="submit">Tenant selecteren</button>
                    </div>
                </noscript>
            </form>
        </section>

        <div id="tenant-loading-status" class="notice" role="status" hidden>
            <strong>Tenantgegevens laden</strong>
            De gekozen tenant wordt geladen. Wacht tot de bijbehorende naam en UUID hieronder zichtbaar zijn.
        </div>

        <div id="selected-tenant-content">
            <div class="notice warning">
                <strong>Verwerking kan enige tijd duren</strong>
                Dien niet direct meerdere wijzigingen voor dezelfde tenant in. Controleer na iedere aanvraag eerst of de API de nieuwe tenantstatus teruggeeft.
            </div>

            <?php if ($selectedTenant !== null): ?>
                <section class="panel" aria-labelledby="selected-tenant-heading">
                    <div class="panel-head">
                        <div>
                            <h2 id="selected-tenant-heading"><?= atm_h($selectedTenant['name']) ?></h2>
                            <p class="tenant-id">Tenant-UUID: <?= atm_h($selectedTenant['uuid']) ?></p>
                        </div>
                    </div>

                    <div class="stats">
                        <div class="stat"><span>Pakketgrootte</span><strong><?= atm_h($selectedTenant['storage']) ?> GB</strong></div>
                        <div class="stat"><span>Gebruik</span><strong><?= atm_h(atm_format_gb($selectedUsage['current'])) ?></strong></div>
                        <div class="stat"><span>Opslaglimiet</span><strong><?= atm_h(atm_format_gb($selectedUsage['limit'])) ?></strong></div>
                        <div class="stat"><span>EDR</span><strong><?= atm_h($selectedTenant['edr']) ?></strong></div>
                    </div>

                    <?php if ($usagePercent !== null): ?>
                        <div class="progress">
                            <div class="progress-label">
                                <span>Opslaggebruik</span>
                                <span><?= atm_h(atm_format_gb($selectedUsage['current'])) ?> van <?= atm_h(atm_format_gb($selectedUsage['limit'])) ?></span>
                            </div>
                            <div class="progress-track" role="progressbar" aria-label="Opslaggebruik" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= atm_h(round($usagePercent, 2)) ?>">
                                <div class="progress-bar" style="width: <?= atm_h(round($usagePercent, 2)) ?>%"></div>
                            </div>
                        </div>
                    <?php else: ?>
                        <p class="help">Het actuele opslaggebruik kon niet worden opgehaald.</p>
                    <?php endif; ?>
                </section>

                <?php if ($mutationsDisabled): ?>
                    <div class="notice warning" role="status">
                        <strong>Wijziging mogelijk nog in verwerking</strong>
                        Pakket- en add-onacties zijn voor deze browsersessie nog ongeveer <?= atm_h($cooldownRemaining) ?> seconden geblokkeerd. Ververs daarna de gegevens en controleer eerst de actuele status.
                    </div>
                <?php endif; ?>

                <div class="grid">
                    <section class="panel" aria-labelledby="package-heading">
                    <h2 id="package-heading">Pakket wijzigen</h2>
                    <p class="help">Opschalen en afschalen worden via dezelfde pakketwijzigingsroute ingediend.</p>
                    <form method="post">
                        <input type="hidden" name="csrf_token" value="<?= atm_h($csrfToken) ?>">
                        <input type="hidden" name="submit_token" value="<?= atm_h($packageSubmitToken) ?>">
                        <input type="hidden" name="action" value="changePackage">
                        <input type="hidden" name="tenantUuid" value="<?= atm_h($selectedTenant['uuid']) ?>">
                        <label for="productName">Doelpakket</label>
                        <select id="productName" name="productName" <?= $mutationsDisabled ? 'disabled' : '' ?>>
                            <?php foreach (ATM_PRODUCTS as $productKey => $productLabel): ?>
                                <option value="<?= atm_h($productKey) ?>" <?= $productKey === $selectedProduct ? 'selected' : '' ?>><?= atm_h($productLabel) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="actions">
                            <button type="submit" <?= $mutationsDisabled ? 'disabled' : '' ?>>Pakketwijziging indienen</button>
                        </div>
                    </form>
                    </section>

                    <section class="panel" aria-labelledby="addon-heading">
                    <h2 id="addon-heading">Add-ons beheren</h2>
                    <p class="help">De storage-add-on vereist een opslaglimiet van minimaal 2 TB. Acronis EDR kan afzonderlijk worden beheerd.</p>
                    <form method="post">
                        <input type="hidden" name="csrf_token" value="<?= atm_h($csrfToken) ?>">
                        <input type="hidden" name="submit_token" value="<?= atm_h($addonSubmitToken) ?>">
                        <input type="hidden" name="tenantUuid" value="<?= atm_h($selectedTenant['uuid']) ?>">
                        <label for="addonName">Add-on</label>
                        <select id="addonName" name="addonName" <?= $mutationsDisabled ? 'disabled' : '' ?>>
                            <?php foreach (ATM_ADDONS as $addonKey => $addonLabel): ?>
                                <option value="<?= atm_h($addonKey) ?>"><?= atm_h($addonLabel) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="actions">
                            <button type="submit" name="action" value="addAddon" <?= $mutationsDisabled ? 'disabled' : '' ?>>Toevoegen</button>
                            <button type="submit" name="action" value="removeAddon" class="secondary" <?= $mutationsDisabled ? 'disabled' : '' ?>>Verwijderen</button>
                        </div>
                    </form>
                    </section>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</main>
<script nonce="<?= atm_h($cspNonce) ?>">
(() => {
    const form = document.getElementById('tenant-selection-form');
    const select = form?.querySelector('[data-tenant-selection]');
    const currentContent = document.getElementById('selected-tenant-content');
    const loadingStatus = document.getElementById('tenant-loading-status');

    if (!(form instanceof HTMLFormElement) || !(select instanceof HTMLSelectElement)) {
        return;
    }

    let submitting = false;
    select.addEventListener('change', () => {
        if (submitting) {
            return;
        }

        submitting = true;
        select.setAttribute('aria-busy', 'true');

        if (currentContent !== null) {
            currentContent.hidden = true;
        }
        if (loadingStatus !== null) {
            loadingStatus.hidden = false;
        }

        if (typeof form.requestSubmit === 'function') {
            form.requestSubmit();
        } else {
            form.submit();
        }
    });
})();
</script>
</body>
</html>