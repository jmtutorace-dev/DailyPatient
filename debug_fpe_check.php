<?php
/**
 * TEMPORARY DIAGNOSTIC — do not leave in production.
 * Tests patient_is_registered() for a guaranteed-new patient name.
 */
require_once 'config.php';

$name = 'TEST BRAND NEW PATIENT ZZZ';

echo "=== STEP 1: Prove the name is clean (no rows anywhere) ===\n";
$r1 = $conn->query("SELECT * FROM daily_records WHERE TRIM(patient_name) = '" . $conn->real_escape_string($name) . "'");
echo "daily_records rows for '$name': " . $r1->num_rows . "\n";
$r2 = $conn->query("SELECT * FROM patients WHERE TRIM(patient_name) = '" . $conn->real_escape_string($name) . "'");
echo "patients rows for '$name': " . $r2->num_rows . "\n";

echo "\n=== STEP 2: patient_is_registered() raw return value ===\n";
$reg = patient_is_registered($conn, $name);
var_dump($reg);

echo "\n=== STEP 2b: var_dump of patient_is_registered for a KNOWN-empty string ===\n";
var_dump(patient_is_registered($conn, ''));

echo "\n=== STEP 2c: What does the add-handler comparison produce? ===\n";
$blocked = false;
if ($reg !== false) {
    $blocked = true;
}
echo 'For new patient: $reg !== false => ' . var_export($reg !== false, true) . " (so \$blocked = " . var_export($blocked, true) . ")\n";

echo "\n=== Feel free to delete debug_fpe_check.php after this ===\n";
