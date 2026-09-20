<?php

declare(strict_types=1);

/**
 * Gujarati strings.
 *
 * Any key missing here falls back to the key itself, not to English — so an
 * untranslated string is visible rather than silently mixed in.
 */

return [
    // Navigation
    'nav.dashboard'   => 'ડેશબોર્ડ',
    'nav.networks'    => 'નેટવર્ક',
    'nav.devices'     => 'ડિવાઇસ',
    'nav.users'       => 'વપરાશકર્તા અને ભૂમિકા',
    'nav.api_keys'    => 'API કી',
    'nav.audit'       => 'ઓડિટ લોગ',
    'nav.customers'   => 'ગ્રાહકો',
    'nav.relays'      => 'રિલે',
    'nav.updates'     => 'અપડેટ',
    'nav.backups'     => 'બેકઅપ',
    'nav.account'     => 'મારું ખાતું',
    'nav.sign_out'    => 'સાઇન આઉટ',
    'nav.platform'    => 'પ્લેટફોર્મ',

    // Authentication
    'auth.sign_in'          => 'સાઇન ઇન',
    'auth.email'            => 'ઈમેલ સરનામું',
    'auth.password'         => 'પાસવર્ડ',
    'auth.forgot'           => 'ભૂલી ગયા?',
    'auth.invalid'          => 'આ વિગતો અમારા રેકોર્ડ સાથે મેળ ખાતી નથી.',
    'auth.signed_out'       => 'તમે સાઇન આઉટ થઈ ગયા છો.',
    'auth.2fa_title'        => 'બે-સ્તરીય સુરક્ષા',
    'auth.2fa_prompt'       => 'તમારી ઓથેન્ટિકેટર એપમાંથી છ અંકનો કોડ દાખલ કરો.',
    'auth.2fa_invalid'      => 'આ કોડ સાચો નથી.',
    'auth.reset_sent'       => 'જો આ સરનામા પર ખાતું હશે, તો રીસેટ લિંક મોકલી દેવાઈ છે.',
    'auth.reset_expired'    => 'આ રીસેટ લિંક અમાન્ય છે અથવા તેની મુદત પૂરી થઈ ગઈ છે.',
    'auth.password_changed' => 'તમારો પાસવર્ડ બદલાઈ ગયો છે.',

    // Common actions
    'action.save'    => 'સાચવો',
    'action.cancel'  => 'રદ કરો',
    'action.delete'  => 'કાઢી નાખો',
    'action.create'  => 'બનાવો',
    'action.edit'    => 'સંપાદિત કરો',
    'action.search'  => 'શોધો',
    'action.filter'  => 'ફિલ્ટર',
    'action.export'  => 'નિકાસ',
    'action.copy'    => 'કૉપિ કરો',
    'action.copied'  => 'કૉપિ થયું',
    'action.approve' => 'મંજૂર કરો',
    'action.revoke'  => 'રદ કરો',
    'action.disable' => 'બંધ કરો',

    // Networks
    'network.title'        => 'નેટવર્ક',
    'network.new'          => 'નવું નેટવર્ક',
    'network.name'         => 'નામ',
    'network.cidr'         => 'એડ્રેસ રેન્જ',
    'network.cidr_hint'    => 'ફક્ત પ્રાઇવેટ રેન્જ (10/8, 172.16/12 અથવા 192.168/16), /16 થી /30 વચ્ચે.',
    'network.created'      => 'નેટવર્ક બની ગયું.',
    'network.updated'      => 'નેટવર્ક અપડેટ થયું.',
    'network.archived'     => 'નેટવર્ક આર્કાઇવ થયું.',
    'network.join_code'    => 'જોડાવાનો કોડ',
    'network.empty_title'  => 'હજી કોઈ નેટવર્ક નથી',
    'network.empty_body'   => 'નેટવર્ક એટલે તમારા ડિવાઇસ વચ્ચે વહેંચાયેલી ખાનગી એડ્રેસ રેન્જ.',

    // Devices
    'device.title'        => 'ડિવાઇસ',
    'device.pending'      => 'મંજૂરીની રાહમાં',
    'device.approved'     => 'ડિવાઇસ મંજૂર થયું.',
    'device.revoked'      => 'ડિવાઇસ રદ થયું.',
    'device.direct'       => 'સીધું',
    'device.relay'        => 'રિલે',
    'device.offline'      => 'ઓફલાઇન',
    'device.direct_hint'  => 'સીધું પીઅર-ટુ-પીઅર. કોઈ ટ્રાફિક અમારા સર્વરમાંથી પસાર થતો નથી.',
    'device.relay_hint'   => 'રિલે મારફતે જોડાયેલું. એજન્ટ પાછળથી સીધું જોડાણ મેળવવાનો પ્રયાસ ચાલુ રાખે છે.',
    'device.empty_title'  => 'હજી કોઈ ડિવાઇસ નથી',
    'device.empty_body'   => 'પહેલું ડિવાઇસ ઉમેરવા માટે મશીન પર ઇન્સ્ટોલ કમાન્ડ ચલાવો.',

    // Updates
    'update.title'          => 'સિસ્ટમ અપડેટ',
    'update.check'          => 'અપડેટ તપાસો',
    'update.available'      => 'અપડેટ ઉપલબ્ધ છે',
    'update.up_to_date'     => 'તમે નવીનતમ વર્ઝન પર છો.',
    'update.apply'          => 'હમણાં અપડેટ કરો',
    'update.rollback'       => 'પાછું ફેરવો',
    'update.history'        => 'અપડેટ ઇતિહાસ',
    'update.breaking'       => 'આ રિલીઝમાં મોટા ફેરફારો છે.',
    'update.confirm'        => 'પહેલાં પૂરું બેકઅપ લેવાય છે. કંઈ નિષ્ફળ જાય તો જૂનું વર્ઝન આપમેળે પાછું આવે છે.',
    'update.token_hint'     => 'એન્ક્રિપ્ટ કરીને સાચવાય છે. ક્યારેય બતાવાતું, લોગ થતું કે પાછું મોકલાતું નથી.',

    // Errors
    'error.403'   => 'તમારી પાસે આ કરવાની પરવાનગી નથી.',
    'error.404'   => 'માગેલી વસ્તુ મળી નહીં.',
    'error.429'   => 'ઘણી બધી વિનંતીઓ. થોડું ધીમે કરો.',
    'error.500'   => 'અમારી બાજુએ કંઈક ખોટું થયું. ભૂલ લોગ થઈ ગઈ છે.',
    'error.csrf'  => 'તમારું સેશન ટોકન ખૂટે છે અથવા સમાપ્ત થયું છે. પેજ ફરી લોડ કરો.',

    // Plan limits
    'limit.devices'  => 'તમારા પ્લાનમાં :limit ડિવાઇસ સુધી મંજૂરી છે અને :used પહેલેથી વપરાયેલા છે.',
    'limit.networks' => 'તમારા પ્લાનમાં :limit નેટવર્કની મંજૂરી છે.',
    'limit.users'    => 'તમારા પ્લાનમાં :limit વપરાશકર્તાની મંજૂરી છે.',
    'limit.upgrade'  => 'મર્યાદા વધારવા માટે અપગ્રેડ કરો.',
];
