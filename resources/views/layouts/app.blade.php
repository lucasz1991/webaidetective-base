<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" style="user-select:none;" class="scroll-smooth">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        
        <x-meta-page-header />
        <title>@yield('title') | {{ config('app.name') }}</title>
        <link rel="apple-touch-icon" sizes="180x180" href="{{ asset('site-images/favicon/favicon.jpg') }}">
        <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('site-images/favicon/favicon.jpg') }}">
        <link rel="icon" type="image/png" sizes="16x16" href="{{ asset('site-images/favicon/favicon.jpg') }}">

        <link rel="stylesheet" href="/adminresources/css/swiper-bundle.min.css">
        <script src="/adminresources/js/swiper-bundle.min.js"></script>
        <link href="{{ URL::asset('adminresources/flatpickr/flatpickr.min.css') }}" rel="stylesheet" type="text/css" />
        <link href="{{ URL::asset('adminresources/choices.js/public/assets/styles/choices.min.css') }}" rel="stylesheet" type="text/css" />
        <script src="{{ URL::asset('adminresources/choices.js/public/assets/scripts/choices.min.js') }}"></script>
        <script src="{{ URL::asset('adminresources/flatpickr/flatpickr.min.js') }}"></script>
        <script src="{{ URL::asset('adminresources/flatpickr/l10n/de.js') }}"></script>

        
        <!-- Styles -->
        @vite(['resources/css/app.css'])

        <!-- Styles -->
        @livewireStyles
    </head>
    <body class=" antialiased ">
        <div id="main" class="snap-y">
            @livewire('user-alert')
            <header class="snap-start">
                @livewire('user-navigation-menu')
            </header>
            <x-page-header />
            <x-pagebuilder-module :position="'top_banner'"/>
            <x-pagebuilder-module :position="'banner'"/>
            <x-pagebuilder-module :position="'bottom_banner'"/>
            <main  class="snap-start z-0">
                <x-pagebuilder-module/>
                <x-pagebuilder-module :position="'above_content'"/>
                {{ $slot }}
                <x-pagebuilder-module :position="'content'"/>
            </main>
        </div>
        <x-pagebuilder-module :position="'footer'"/>
        @livewire('footer')
        @livewire('tools.chatbot')
        
        @stack('modals')
        
        
        <!-- Scripts -->
        @vite(['resources/js/app.js'])
        @livewireScripts
        <!-- <script id="Cookiebot" src="https://consent.cookiebot.com/uc.js" data-cbid="90a1af19-c1d7-46b9-9855-b9b076ac7501" data-blockingmode="auto" type="text/javascript"></script> -->
        <!-- <script async src="https://www.googletagmanager.com/gtag/js?id=AW-16808641054"></script> <script> window.dataLayer = window.dataLayer || []; function gtag(){dataLayer.push(arguments);} gtag('js', new Date()); gtag('config', 'AW-16808641054'); </script>-->
       <!-- <script data-cookieconsent="ignore">    window.dataLayer = window.dataLayer || [];    function gtag() {        dataLayer.push(arguments);    }    gtag("consent", "default", {        ad_personalization: "denied",        ad_storage: "denied",        ad_user_data: "denied",        analytics_storage: "denied",        functionality_storage: "denied",        personalization_storage: "denied",        security_storage: "granted",        wait_for_update: 500,    });    gtag("set", "ads_data_redaction", true);    gtag("set", "url_passthrough", false);</script>
        <script type="text/javascript">
        ((d,i,m)=>{ct=t=>d.createTextNode(t);ce=e=>d.createElement(e);d.querySelectorAll(i)
        .forEach(e=>{const a=ce('a'),div=ce('div'),p=ce('p'),s=e.dataset.cookieblockSrc,sp=
        /google\.com\/maps\/embed/.test(s)?'Google Maps':/player\.vimeo\.com\/video\//
        .test(s)?'Vimeo':/youtube(-nocookie)?\.com\/embed\//.test(s)?'YouTube':undefined;
        if(!sp)return;div.innerHTML=`<div style="background-color:#CCC;display:inline-`+
        `block;height:${e.height}px;position:relative;width:${e.width}px;"><div style=`+
        '"background-color:#848484;border-radius:15px;height:50%;position:absolute;'+
        'transform:translate(50%,50%);width:50%;"><p style="color:#FFF;font-size:7.5em;'+
        'position:relative;top:50%;left:50%;margin:0;text-align:center;transform:translate'
        +'-50%,-50%);">&ctdot;</p></div>';div.classList.add(`cookieconsent-optout-${m}`);
        a.textContent=`accept ${m} cookies`;a.href='javascript:Cookiebot.renew()';p.append(
        ct('Please '), a, ct(` to view this ${sp} content.`));div.append(p);e.parentNode
        .insertBefore(div, e);})})(document, 'iframe[data-cookieblock-src]', 'marketing')
        </script> -->
    </body>
</html>
