<?php
// Hetzner Cloud API config. Primary source is the database - set the real
// token from Settings -> Integrations in the panel UI (no file editing
// needed). The values below are only the fallback used before anything has
// been saved there.
$HETZNER_API_TOKEN = 'CHANGE_THIS_HETZNER_API_TOKEN';

// Leave null to auto-detect (works when this token's project has exactly
// one server). Set a numeric ID here (or via Settings) if the project has
// more than one.
$HETZNER_SERVER_ID = null;

if (isset($pdo)) {
    try {
        $row = $pdo->query("SELECT api_token, server_id FROM hetzner_settings WHERE id=1")->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            if ($row['api_token'] !== '') $HETZNER_API_TOKEN = $row['api_token'];
            if ($row['server_id'] !== '') $HETZNER_SERVER_ID = (int) $row['server_id'];
        }
    } catch (PDOException $e) {
        // hetzner_settings table not migrated yet - keep the fallback above.
    }
}
