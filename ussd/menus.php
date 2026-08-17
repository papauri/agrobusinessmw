<?php
// === Menu Definitions and Validation ===
// Centralized menu texts and valid options for USSD app with paginated districts

$menu_texts = [
    // Language selection
    'language_selection' => [
        'en' => "🌱[AGRO-BIZ]🌱\nWelcome to AgroBusiness\n\n1. English\n2. Chichewa",
        'ci' => "🌱[AGRO-BIZ]🌱\nTakulandilani ku AgroBusiness\n\n1. English\n2. Chichewa"
    ],
    
    // Main menu
    'main_menu' => [
        // TRIMMED 2026-08-17 to make room for "10 Register".
        //
        // The old wording was 234 characters in English and 271 in Chichewa,
        // against a documented 182-character CON limit — so these pages were
        // over the ceiling before an option was ever added to them. Either the
        // operator is more generous than the documentation or this menu has
        // been truncating in production all along; neither can be settled
        // without a live shortcode. Trimming rather than extending is the only
        // change that is safe under both readings.
        //
        // The NUMBERS ARE UNCHANGED. 1-9 mean exactly what they meant before,
        // because callers know them by heart; only the labels are shorter.
        'en' => "🌾AGRO MENU\n1 Crop Prices\n2 Market Insights\n3 Pest Control\n4 Farming Practices\n5 Community Q&A\n6 Farming Info\n7 Find Sellers\n8 Find Buyers\n9 Weather\n10 Register\n0 Back\n00 Language",
        'ci' => "🌾MENU YA AGRO\n1 Mitengo ya Mbeu\n2 Zamsika\n3 Tizirombo\n4 Njira Zolima\n5 Mafunso\n6 Zaulimi\n7 Pezani Ogulitsa\n8 Pezani Ogula\n9 Mvula\n10 Lembetsani\n0 Kubwerera\n00 Chinenero"
    ],

    // Weather forecast - paginated districts
    'weather_forecast' => [
        'en' => [
            1 => "🌤️[WEATHER PG 1/3]🌤️\nSelect district:\n1. Lilongwe 🏙️\n2. Blantyre 🏢\n3. Mzuzu 🌄\n4. Mchinji 🌍\n5. Ntchisi 🗺️\n6. Dedza ⛰️\n7. Kasungu 🌳\n8. Nkhata-Bay 🌊\n0. Back 🔙\n9. Next ▶️\n00.🌐",
            2 => "🌤️[WEATHER PG 2/3]🌤️\nSelect district:\n1. Rumphi 🏞️\n2. Karonga 🐟\n3. Thyolo 🍵\n4. Chitipa 🛤️\n5. Mangochi 🏝️\n6. Chikwawa 🌅\n7. Zomba 🏫\n8. Nkhotakota ⛵\n0. Back 🔙\n9. Next ▶️\n00.🌐",
            3 => "🌤️[WEATHER PG 3/3]🌤️\nSelect district:\n1. Ntcheu 🌄\n2. Balaka 🛣️\n3. Mulanje ⛰️\n4. Machinga 🌄\n5. Phalombe 🌿\n6. Dowa 🌱\n7. Likoma 🏝️\n8. Salima ⛵\n0. Back 🔙\n9. Main Menu\n00.🌐"
        ],
        'ci' => [
            1 => "🌤️[MVULA PG 1/3]🌤️\nChigawo:\n1. Lilongwe 🏙️\n2. Blantyre 🏢\n3. Mzuzu 🌄\n4. Mchinji 🌍\n5. Ntchisi 🗺️\n6. Dedza ⛰️\n7. Kasungu 🌳\n8. Nkhata-Bay 🌊\n0. Kubwerera 🔙\n9. Patsogolo ▶️\n00.🌐",
            2 => "🌤️[MVULA PG 2/3]🌤️\nChigawo:\n1. Rumphi 🏞️\n2. Karonga 🐟\n3. Thyolo 🍵\n4. Chitipa 🛤️\n5. Mangochi 🏝️\n6. Chikwawa 🌅\n7. Zomba 🏫\n8. Nkhotakota ⛵\n0. Kubwerera 🔙\n9. Patsogolo ▶️\n00.🌐",
            3 => "🌤️[MVULA PG 3/3]🌤️\nChigawo:\n1. Ntcheu 🌄\n2. Balaka 🛣️\n3. Mulanje ⛰️\n4. Machinga 🌄\n5. Phalombe 🌿\n6. Dowa 🌱\n7. Likoma 🏝️\n8. Salima ⛵\n0. Kubwerera 🔙\n9. Menu Yaikulu\n00.🌐"
        ]
    ],

    // District selection - paginated
    'district_selection' => [
        'en' => [
            1 => "📍[DISTRICTS PG 1/3]📍\nSelect district:\n1. Lilongwe 🏙️\n2. Blantyre 🏢\n3. Mzuzu 🌄\n4. Mchinji 🌍\n5. Ntchisi 🗺️\n6. Dedza ⛰️\n7. Kasungu 🌳\n8. Nkhata-Bay 🌊\n0. Back 🔙\n9. Next ▶️\n00.🌐",
            2 => "📍[DISTRICTS PG 2/3]📍\nSelect district:\n1. Rumphi 🏞️\n2. Karonga 🐟\n3. Thyolo 🍵\n4. Chitipa 🛤️\n5. Mangochi 🏝️\n6. Chikwawa 🌅\n7. Zomba 🏫\n8. Nkhotakota ⛵\n0. Back 🔙\n9. Next ▶️\n00.🌐",
            3 => "📍[DISTRICTS PG 3/3]📍\nSelect district:\n1. Ntcheu 🌄\n2. Balaka 🛣️\n3. Mulanje ⛰️\n4. Machinga 🌄\n5. Phalombe 🌿\n6. Dowa 🌱\n7. Likoma 🏝️\n8. Salima ⛵\n0. Back 🔙\n9. Main Menu\n00.🌐"
        ],
        'ci' => [
            1 => "📍[MAGAWO PG 1/3]📍\nChigawo:\n1. Lilongwe 🏙️\n2. Blantyre 🏢\n3. Mzuzu 🌄\n4. Mchinji 🌍\n5. Ntchisi 🗺️\n6. Dedza ⛰️\n7. Kasungu 🌳\n8. Nkhata-Bay 🌊\n0. Kubwerera 🔙\n9. Patsogolo ▶️\n00.🌐",
            2 => "📍[MAGAWO PG 2/3]📍\nChigawo:\n1. Rumphi 🏞️\n2. Karonga 🐟\n3. Thyolo 🍵\n4. Chitipa 🛤️\n5. Mangochi 🏝️\n6. Chikwawa 🌅\n7. Zomba 🏫\n8. Nkhotakota ⛵\n0. Kubwerera 🔙\n9. Patsogolo ▶️\n00.🌐",
            3 => "📍[MAGAWO PG 3/3]📍\nChigawo:\n1. Ntcheu 🌄\n2. Balaka 🛣️\n3. Mulanje ⛰️\n4. Machinga 🌄\n5. Phalombe 🌿\n6. Dowa 🌱\n7. Likoma 🏝️\n8. Salima ⛵\n0. Kubwerera 🔙\n9. Menu Yaikulu\n00.🌐"
        ]
    ],

    // Crop Prices sub-menu
    'crop_prices_menu' => [
        'en' => "💰[CROP PRICES]💰\nSelect:\n1. By District (all crops)\n2. By Crop\n0. Back 🔙\n00. Change Language 🌐",
        'ci' => "💰[MITENGO YA MBEU]💰\nSankhani:\n1. Pa Chigawo (mbeu zonse)\n2. Pa Mbeu\n0. Kubwerera 🔙\n00. Sinthani Chinenero 🌐"
    ],

    // Expanded crop selection for prices (all 9 crops, pos = crop_id)
    'crop_prices_crop' => [
        'en' => "🌽[SELECT CROP]🌽\n1. Maize\n2. Tobacco\n3. Groundnuts\n4. Soybeans\n5. Rice\n6. Cotton\n7. Tea\n8. Coffee\n9. Beans\n0. Back 🔙\n00. Change Language 🌐",
        'ci' => "🌽[SANKHANI MBEU]🌽\n1. Chimanga\n2. Fodya\n3. Nthola\n4. Soya\n5. Mpunga\n6. Thonje\n7. Tii\n8. Khofi\n9. Nyemba\n0. Kubwerera 🔙\n00. Sinthani Chinenero 🌐"
    ],

    // Crop selection (used by pest control & farming practices — 3 crops only)
    'crop_selection' => [
        'en' => "🌽[CROPS]🌽\nSelect crop:\n1. Maize 🌽\n2. Tobacco 🍂\n3. Groundnuts 🥜\n0. Back 🔙\n00. Change Language 🌐",
        'ci' => "🌽[MBEU]🌽\nSankhani mbeu:\n1. Chimanga 🌽\n2. Fodya 🍂\n3. Nthola 🥜\n0. Kubwerera 🔙\n00. Sinthani Chinenero 🌐"
    ],

    // Practice selection
    'practice_selection' => [
        'en' => "🚜[PRACTICES]🚜\nSelect practice:\n1. Planting 🌱\n2. Harvesting 🌿\n3. Growing 🌞\n0. Back 🔙\n00. Change Language 🌐",
        'ci' => "🚜[NJIRA]🚜\nSankhani njira:\n1. Kubzala 🌱\n2. Kukolola 🌿\n3. Kulima 🌞\n0. Kubwerera 🔙\n00. Sinthani Chinenero 🌐"
    ],

    // Back option (appended to result pages)
    'back_option' => [
        'en' => "\n0. Back 🔙\n9. Main Menu\n00. Change Language 🌐",
        'ci' => "\n0. Kubwerera 🔙\n9. Menu Yaikulu\n00. Sinthani Chinenero 🌐"
    ],

    // Find Sellers / Find Buyers result lines.
    // Kept terse on purpose: a CON page is 182 characters and the back menu
    // above already spends 45 of them (56 in Chichewa), so every word here costs
    // a listing the caller could otherwise have seen.
    'directory' => [
        'no_number' => ['en' => 'no number',        'ci' => 'palibe nambala'],
        'no_crops'  => ['en' => 'crops not listed', 'ci' => 'mbewu sizinalembedwa'],
        'more'      => ['en' => '+{n} more',        'ci' => '+{n} ena']
    ],

    // Registration over USSD. One question per page, so every string here has a
    // whole 182-character page to itself except `confirm`, which carries a
    // summary. Terse on purpose — see ussd/registration.php.
    'registration' => [
        'role' => [
            'en' => "REGISTER\nI am a:\n1 Farmer\n2 Seller\n3 Buyer\n0 Back",
            'ci' => "LEMBETSANI\nNdine:\n1 Mlimi\n2 Wogulitsa\n3 Wogula\n0 Kubwerera"
        ],
        'name' => [
            'en' => 'Enter your full name:',
            'ci' => 'Lembani dzina lanu lonse:'
        ],
        'village' => [
            'en' => 'Enter your village or town:',
            'ci' => 'Lembani mudzi kapena tauni yanu:'
        ],
        'crops' => [
            'en' => 'Enter crop numbers, e.g. 1,3',
            'ci' => 'Lembani manambala a mbewu, mwachitsanzo 1,3'
        ],
        'business' => [
            'en' => 'Enter your business name:',
            'ci' => 'Lembani dzina la bizinesi yanu:'
        ],
        'confirm' => [
            'en' => "Check:\n{summary}\n1 Send\n0 Cancel",
            'ci' => "Onani:\n{summary}\n1 Tumizani\n0 Lekani"
        ],
        'role_farmer' => ['en' => 'Farmer',  'ci' => 'Mlimi'],
        'role_seller' => ['en' => 'Seller',  'ci' => 'Wogulitsa'],
        'role_buyer'  => ['en' => 'Buyer',   'ci' => 'Wogula'],
        'done' => [
            'en' => "Registered. Ref {ref}\nWe will review it and contact you.",
            'ci' => "Mwalembetsa. Nambala {ref}\nTidzayang'ana ndi kukulankhulani."
        ],
        'already' => [
            'en' => "This number is already registered.\nRef {ref} ({status})",
            'ci' => "Nambala imeneyi yalembetsedwa kale.\nNambala {ref} ({status})"
        ],
        // Statuses, for the `already` message. Same three values as the table.
        'status_pending'  => ['en' => 'pending',  'ci' => 'ikuyembekezera'],
        'status_approved' => ['en' => 'approved', 'ci' => 'yavomerezedwa'],
        'status_denied'   => ['en' => 'denied',   'ci' => 'yakanidwa'],
        'cancelled' => [
            'en' => 'Registration cancelled. Nothing was saved.',
            'ci' => 'Kulembetsa kwaletsedwa. Palibe chomwe chasungidwa.'
        ],
        'bad_choice'   => ['en' => 'Not a valid choice.',           'ci' => 'Sankho losavomerezeka.'],
        'bad_name'     => ['en' => 'Enter your name.',              'ci' => 'Lembani dzina lanu.'],
        'bad_village'  => ['en' => 'Enter your village or town.',   'ci' => 'Lembani mudzi kapena tauni.'],
        'bad_crops'    => ['en' => 'Use the numbers shown.',        'ci' => 'Gwiritsani manambala omwe ali pamwamba.'],
        'bad_business' => ['en' => 'Enter your business name.',     'ci' => 'Lembani dzina la bizinesi.'],
        'bad_phone' => [
            'en' => 'We could not read your number. Please register at agrobusinessmw.com',
            'ci' => 'Sitinathe kuwerenga nambala yanu. Lembetsani pa agrobusinessmw.com'
        ],
        'failed' => [
            'en' => 'Registration is unavailable right now. Please try again later.',
            'ci' => 'Kulembetsa sikukugwira ntchito pano. Yesaninso pambuyo pake.'
        ]
    ],

    // Error messages
    'errors' => [
        'invalid' => [
            'en' => "END ❌[ERROR]❌\nInvalid option. Try again.",
            'ci' => "END ❌[ZOLAKWIKA]❌\nSankho losavomerezeka. Yesaninso."
        ],
        'no_data' => [
            'en' => "END ⚠️[NOTICE]⚠️\nNo info available.",
            'ci' => "END ⚠️[CHENJEZO]⚠️\nPalibe zambiri."
        ]
    ],

    // Exit message
    'exit' => [
        'en' => "END 🌱[THANKS!]🌱\nThank you! 👋",
        'ci' => "END 🌱[ZIKOMO!]🌱\nZikomo! 👋"
    ]
];

// Validation rules
$valid_options = [
    'language' => ['1', '2'],
    'main_menu' => ['1', '2', '3', '4', '5', '6', '7', '8', '9', '10', '0'],
    'weather_districts' => [
        1 => ['1', '2', '3', '4', '5', '6', '7', '8', '0', '9'], // Page 1 — 0=Back
        2 => ['1', '2', '3', '4', '5', '6', '7', '8', '0', '9'], // Page 2 — 0=Back
        3 => ['1', '2', '3', '4', '5', '6', '7', '8', '0', '9'] // Page 3 — 0=Back, 9=Main Menu
    ],
    'districts' => [
        1 => ['1', '2', '3', '4', '5', '6', '7', '8', '0', '9'], // Page 1 — 0=Back
        2 => ['1', '2', '3', '4', '5', '6', '7', '8', '0', '9'], // Page 2 — 0=Back
        3 => ['1', '2', '3', '4', '5', '6', '7', '8', '0', '9'] // Page 3 — 0=Back, 9=Main Menu
    ],
    'crop_prices' => ['1', '2', '0'],
    'price_crops' => ['1','2','3','4','5','6','7','8','9','0'],
    'crops' => ['1', '2', '3', '0'],
    'practices' => ['1', '2', '3', '0'],
    'results' => ['0']
];

// Practice types mapping
$practice_types = [
    '1' => 'Planting',
    '2' => 'Harvesting',
    '3' => 'Growing'
];

// NOTE: District weather coordinates live in config.php ($district_coords),
// keyed by real DB district IDs. A duplicate table used to live here but had
// IDs 17-24 shifted by one (17=Balaka instead of Ntcheu, etc.), and because
// menus.php loads after config.php it silently overrode the correct table —
// making page-3 weather return the wrong location. Do not redefine it here.
?>