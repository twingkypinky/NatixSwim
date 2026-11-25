<?php
// ============================================================
// config/wa-config.php — pengaturan WhatsApp bersama (Chat Log, Gaji, Progres Murid)
// Pilih provider lewat environment variable (Railway: Variables) atau isi nilai bawaan di bawah ini.
//
//   NATIX_WA_PROVIDER   cloud  = WhatsApp Cloud API resmi Meta (disarankan: tanpa QR, tanpa risiko blokir)
//                       baileys = gateway tidak resmi (butuh scan QR)
//   NATIX_WA_TOKEN      Access token dari Meta (WhatsApp → API Setup)
//   NATIX_WA_PHONE_ID   Phone number ID dari Meta
//   NATIX_WA_VERIFY     Verify token webhook (bebas, samakan dengan yang diisi di Meta)
//   NATIX_WA_SECRET     App Secret (Settings → Basic) agar webhook diverifikasi
// ============================================================
foreach ([
    'WA_PROVIDER'     => [getenv('NATIX_WA_PROVIDER'), 'cloud'],
    'WA_TOKEN'        => [getenv('NATIX_WA_TOKEN'),    'EAGRcHMWVQeIBTK1Ec6lvKXULdWsKIS2WGqjizLpLWxZBhOrLyuNtqqrhbMCKPZAPPG1ExwLyUBluvjUGgi8VZBSv29nVsKTCAbDQr8f5RZBUP7IWtkQDCzrCJB7QZA8MJHYfWqbkpDoI0UEDItKrsVWoIuAyEw5tCmEqrMcGGNl34axCa2sBIDPT6vPQMtZABMUBQSiYgt4V75eRGoW4SmZBTN9fsafZAyiI9ppqHVz6qEUYPMit8eE6nMf8DUuYfAc5qhkm1lRWslzH9s3fupHy'],
    'WA_PHONE_ID'     => [getenv('NATIX_WA_PHONE_ID'), '1524498660736520'],
    'WA_VERIFY_TOKEN' => [getenv('NATIX_WA_VERIFY'),   'natix-swim-verify'],
    'WA_APP_SECRET'   => [getenv('NATIX_WA_SECRET'),   ''],
] as $k => [$env, $def]) if (!defined($k)) define($k, $env ?: $def);
