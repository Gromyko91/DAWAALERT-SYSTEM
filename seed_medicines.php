<?php
require 'db.php';

$conn->query("
    CREATE TABLE IF NOT EXISTS medicines (
        id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(255) NOT NULL UNIQUE,
        category VARCHAR(100) DEFAULT 'General',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )
");

$column_check = $conn->query("SHOW COLUMNS FROM medicines LIKE 'category'");
if($column_check && $column_check->num_rows === 0){
    $conn->query("ALTER TABLE medicines ADD COLUMN category VARCHAR(100) DEFAULT 'General' AFTER name");
}

$medicines = [
    'Analgesics and Anti-inflammatory' => [
        'Acetylsalicylic acid',
        'Codeine',
        'Diclofenac',
        'Ibuprofen',
        'Morphine',
        'Paracetamol',
        'Tramadol'
    ],
    'Antibiotics' => [
        'Amikacin',
        'Amoxicillin',
        'Amoxicillin with clavulanic acid',
        'Ampicillin',
        'Azithromycin',
        'Benzathine benzylpenicillin',
        'Benzylpenicillin',
        'Cefalexin',
        'Cefixime',
        'Cefotaxime',
        'Ceftriaxone',
        'Chloramphenicol',
        'Ciprofloxacin',
        'Clarithromycin',
        'Clindamycin',
        'Cloxacillin',
        'Co-trimoxazole',
        'Doxycycline',
        'Erythromycin',
        'Gentamicin',
        'Imipenem with cilastatin',
        'Meropenem',
        'Metronidazole',
        'Nitrofurantoin',
        'Oxacillin',
        'Penicillin V',
        'Streptomycin',
        'Tetracycline',
        'Tinidazole',
        'Vancomycin'
    ],
    'Antimalarials and Antiparasitics' => [
        'Albendazole',
        'Artemether',
        'Artemether-lumefantrine',
        'Artesunate',
        'Chloroquine',
        'Mebendazole',
        'Niclosamide',
        'Permethrin',
        'Quinine'
    ],
    'Antifungals and Antivirals' => [
        'Aciclovir',
        'Fluconazole',
        'Lamivudine',
        'Miconazole',
        'Nevirapine',
        'Nystatin',
        'Tenofovir disoproxil fumarate',
        'Zidovudine'
    ],
    'Cardiovascular' => [
        'Amiloride',
        'Amlodipine',
        'Atenolol',
        'Atorvastatin',
        'Candesartan',
        'Clopidogrel',
        'Digoxin',
        'Diltiazem',
        'Enalapril',
        'Enoxaparin',
        'Frusemide',
        'Heparin',
        'Hydralazine',
        'Hydrochlorothiazide',
        'Labetalol',
        'Lisinopril',
        'Losartan',
        'Methyldopa',
        'Metoprolol',
        'Nifedipine',
        'Nitroglycerin',
        'Propranolol',
        'Spironolactone',
        'Tranexamic acid',
        'Warfarin'
    ],
    'Diabetes and Endocrine' => [
        'Glibenclamide',
        'Gliclazide',
        'Insulin isophane',
        'Insulin soluble',
        'Levothyroxine',
        'Metformin'
    ],
    'Respiratory and Allergy' => [
        'Budesonide',
        'Cetirizine',
        'Chlorphenamine',
        'Diphenhydramine',
        'Ipratropium bromide',
        'Salbutamol'
    ],
    'Emergency and Critical Care' => [
        'Activated charcoal',
        'Atropine',
        'Calcium gluconate',
        'Diazepam',
        'Dobutamine',
        'Dopamine',
        'Epinephrine',
        'Ketamine',
        'Lidocaine',
        'Magnesium sulfate',
        'Midazolam',
        'Naloxone',
        'Norepinephrine',
        'Oxygen',
        'Ringer lactate',
        'Sodium bicarbonate',
        'Sodium chloride'
    ],
    'Gastrointestinal and Nutrition' => [
        'Folic acid',
        'Ferrous sulfate',
        'Lactulose',
        'Loperamide',
        'Metoclopramide',
        'Omeprazole',
        'Ondansetron',
        'Oral rehydration salts',
        'Pyridoxine',
        'Zinc sulfate'
    ],
    'Neurology and Mental Health' => [
        'Amitriptyline',
        'Carbamazepine',
        'Fluoxetine',
        'Haloperidol',
        'Lorazepam',
        'Phenobarbital',
        'Phenytoin',
        'Valproic acid'
    ],
    'Steroids and Immunology' => [
        'Azathioprine',
        'Betamethasone',
        'Cyclophosphamide',
        'Cyclosporine',
        'Dexamethasone',
        'Hydrocortisone',
        'Methotrexate',
        'Prednisolone',
        'Prednisone',
        'Tamoxifen'
    ],
    'Dermatology and Topicals' => [
        'Benzyl benzoate',
        'Chlorhexidine',
        'Clobetasol',
        'Sulfadiazine'
    ],
    'Obstetrics and Gynecology' => [
        'Clomifene',
        'Ethinylestradiol with levonorgestrel',
        'Levonorgestrel',
        'Misoprostol'
    ],
    'Tuberculosis and Public Health' => [
        'Ethambutol',
        'Isoniazid',
        'Pyrazinamide',
        'Rabies vaccine',
        'Rifampicin'
    ],
    'General' => [
        'Allopurinol'
    ]
];

$stmt = $conn->prepare("
    INSERT INTO medicines (name, category) VALUES (?, ?)
    ON DUPLICATE KEY UPDATE category = VALUES(category)
");
$inserted = 0;

foreach ($medicines as $category => $items) {
    foreach ($items as $medicine) {
        $stmt->bind_param("ss", $medicine, $category);
        $stmt->execute();
        $inserted += $stmt->affected_rows > 0 ? 1 : 0;
    }
}

$stmt->close();
$conn->close();

echo "Medicine catalog seeded or updated. Processed {$inserted} medicine rows." . PHP_EOL;
