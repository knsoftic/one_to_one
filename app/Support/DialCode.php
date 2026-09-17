<?php

namespace App\Support;

/**
 * Maps a phone number's international dial code to an ISO 3166-1 alpha-2 country, so ads can be
 * targeted by country without asking the user (their own number is app data they gave us).
 * Longest prefix wins; codes shared by several countries fall back to the most populous.
 */
final class DialCode
{
    /** dial code (no "+") => ISO2. Ordered longest-first at match time. */
    private const CODES = [
        '1' => 'US', '1242' => 'BS', '1246' => 'BB', '1264' => 'AI', '1268' => 'AG', '1284' => 'VG',
        '1345' => 'KY', '1441' => 'BM', '1473' => 'GD', '1671' => 'GU', '1758' => 'LC', '1767' => 'DM',
        '1809' => 'DO', '1868' => 'TT', '1876' => 'JM',
        '7' => 'RU', '20' => 'EG', '27' => 'ZA', '30' => 'GR', '31' => 'NL', '32' => 'BE', '33' => 'FR',
        '34' => 'ES', '36' => 'HU', '39' => 'IT', '40' => 'RO', '41' => 'CH', '43' => 'AT', '44' => 'GB',
        '45' => 'DK', '46' => 'SE', '47' => 'NO', '48' => 'PL', '49' => 'DE', '51' => 'PE', '52' => 'MX',
        '53' => 'CU', '54' => 'AR', '55' => 'BR', '56' => 'CL', '57' => 'CO', '58' => 'VE', '60' => 'MY',
        '61' => 'AU', '62' => 'ID', '63' => 'PH', '64' => 'NZ', '65' => 'SG', '66' => 'TH', '81' => 'JP',
        '82' => 'KR', '84' => 'VN', '86' => 'CN', '90' => 'TR', '91' => 'IN', '92' => 'PK', '93' => 'AF',
        '94' => 'LK', '95' => 'MM', '98' => 'IR', '211' => 'SS', '212' => 'MA', '213' => 'DZ', '216' => 'TN',
        '218' => 'LY', '220' => 'GM', '221' => 'SN', '233' => 'GH', '234' => 'NG', '249' => 'SD',
        '250' => 'RW', '251' => 'ET', '254' => 'KE', '255' => 'TZ', '256' => 'UG', '260' => 'ZM',
        '263' => 'ZW', '351' => 'PT', '353' => 'IE', '354' => 'IS', '358' => 'FI', '359' => 'BG',
        '370' => 'LT', '371' => 'LV', '372' => 'EE', '380' => 'UA', '381' => 'RS', '385' => 'HR',
        '386' => 'SI', '420' => 'CZ', '421' => 'SK', '852' => 'HK', '853' => 'MO', '855' => 'KH',
        '856' => 'LA', '870' => 'PN', '880' => 'BD', '886' => 'TW', '960' => 'MV', '961' => 'LB',
        '962' => 'JO', '963' => 'SY', '964' => 'IQ', '965' => 'KW', '966' => 'SA', '967' => 'YE',
        '968' => 'OM', '970' => 'PS', '971' => 'AE', '972' => 'IL', '973' => 'BH', '974' => 'QA',
        '975' => 'BT', '976' => 'MN', '977' => 'NP', '992' => 'TJ', '993' => 'TM', '994' => 'AZ',
        '995' => 'GE', '996' => 'KG', '998' => 'UZ',
    ];

    /** The ISO2 country of an international phone number, or null when it can't be told. */
    public static function country(?string $phone): ?string
    {
        $digits = ltrim(preg_replace('/\D+/', '', (string) $phone), '0');
        if ($digits === '') {
            return null;
        }
        // Longest matching prefix (up to 4 digits) wins.
        for ($length = min(4, strlen($digits)); $length >= 1; $length--) {
            $prefix = substr($digits, 0, $length);
            if (isset(self::CODES[$prefix]) && strlen(self::CODES[$prefix]) === 2) {
                return self::CODES[$prefix];
            }
        }

        return null;
    }

    /** ISO2 => English country name, for the admin targeting picker (only the ones we map). */
    public static function countries(): array
    {
        static $names = [
            'US' => 'United States', 'GB' => 'United Kingdom', 'PK' => 'Pakistan', 'IN' => 'India',
            'BD' => 'Bangladesh', 'AE' => 'United Arab Emirates', 'SA' => 'Saudi Arabia', 'QA' => 'Qatar',
            'KW' => 'Kuwait', 'OM' => 'Oman', 'BH' => 'Bahrain', 'AF' => 'Afghanistan', 'CA' => 'Canada',
            'AU' => 'Australia', 'NZ' => 'New Zealand', 'ZA' => 'South Africa', 'NG' => 'Nigeria',
            'EG' => 'Egypt', 'MA' => 'Morocco', 'TR' => 'Turkey', 'ID' => 'Indonesia', 'MY' => 'Malaysia',
            'SG' => 'Singapore', 'PH' => 'Philippines', 'CN' => 'China', 'JP' => 'Japan', 'KR' => 'South Korea',
            'DE' => 'Germany', 'FR' => 'France', 'IT' => 'Italy', 'ES' => 'Spain', 'NL' => 'Netherlands',
            'SE' => 'Sweden', 'NO' => 'Norway', 'DK' => 'Denmark', 'IE' => 'Ireland', 'BR' => 'Brazil',
            'MX' => 'Mexico', 'AR' => 'Argentina', 'RU' => 'Russia', 'UA' => 'Ukraine', 'LK' => 'Sri Lanka',
            'NP' => 'Nepal', 'IR' => 'Iran', 'IQ' => 'Iraq', 'JO' => 'Jordan', 'LB' => 'Lebanon',
            'KE' => 'Kenya', 'GH' => 'Ghana', 'TZ' => 'Tanzania', 'UG' => 'Uganda',
        ];
        asort($names);

        return $names;
    }
}
