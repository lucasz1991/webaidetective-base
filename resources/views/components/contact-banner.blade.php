        <div class="pt-8 pb-4 bg-white text-center overflow-hidden">
            
            <div class="md:flex space-x-8 max-w-7xl mx-auto px-4 sm:px-6 lg:px-8" 
                x-data="{ shown: false }" 
                x-intersect.once="setTimeout(() => shown = true, 500)">
                
                <div 
                    x-transition
                    x-cloak
                    :class="{ 'translate-x-0 opacity-100': shown, '-translate-x-[2000px] opacity-0': !shown }" 
                    class="md:w-6/12 relative rounded-lg overflow-hidden shadow-lg transform transition-all duration-500 ease-out mb-5"
                >
                    <div style="
                        position: absolute;
                        top: 0;
                        left: 0;
                        width: 100%;
                        height: 100%;
                        pointer-events: none; 
                        background: #e5d4bc5c;
                    "></div>
                    <iframe 
                        data-cookieblock-src="https://www.google.com/maps/embed?pb=!1m14!1m8!1m3!1d592.2874158162278!2d10.016986!3d53.5729455!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x47b18ed3de0948f7%3A0x93bc0038075543bf!2sKanalstra%C3%9Fe%2014%2C%2022085%20Hamburg!5e0!3m2!1sde!2sde!4v1736895623901!5m2!1sde!2sde" 
                        data-cookieconsent="marketing"
                        width="100%" 
                        height="300px" 
                        style="border:0;" 
                        allowfullscreen="" 
                        loading="lazy" 
                        referrerpolicy="no-referrer-when-downgrade">
                    </iframe>
                </div>
                <div x-cloak class="md:w-6/12 transform transition-all duration-500 ease-out" 
                    x-transition
                    x-cloak
                    :class="{ 'translate-x-0 opacity-100': shown, 'translate-x-[2000px] opacity-0': !shown }" 
                    >
                        <div class="flex items-center grow-0 basis-auto text-left">
                            <div class="flex flex-wrap h-min justify-center">
                                
                                <div class="mb-8 w-full ">
                                    <div class="flex items-start">
                                        <div class="srink-0">
                                            <div class="inline-block rounded-md bg-[#cccccc] p-4 text-white">
                                                <svg class="w-6 h-6 fill-white" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 576 512"><path d="M408 120c0 54.6-73.1 151.9-105.2 192c-7.7 9.6-22 9.6-29.6 0C241.1 271.9 168 174.6 168 120C168 53.7 221.7 0 288 0s120 53.7 120 120zm8 80.4c3.5-6.9 6.7-13.8 9.6-20.6c.5-1.2 1-2.5 1.5-3.7l116-46.4C558.9 123.4 576 135 576 152l0 270.8c0 9.8-6 18.6-15.1 22.3L416 503l0-302.6zM137.6 138.3c2.4 14.1 7.2 28.3 12.8 41.5c2.9 6.8 6.1 13.7 9.6 20.6l0 251.4L32.9 502.7C17.1 509 0 497.4 0 480.4L0 209.6c0-9.8 6-18.6 15.1-22.3l122.6-49zM327.8 332c13.9-17.4 35.7-45.7 56.2-77l0 249.3L192 449.4 192 255c20.5 31.3 42.3 59.6 56.2 77c20.5 25.6 59.1 25.6 79.6 0zM288 152a40 40 0 1 0 0-80 40 40 0 1 0 0 80z"/></svg>
                                            </div>
                                        </div>
                                        <div class="ml-6 grow">
                                            <p class="mb-2 font-bold ">
                                                Adresse
                                            </p>
                                            <p class="text-sm text-neutral-500">
                                                Kanalstraße 14<br> 22085 Hamburg<br> Deutschland
                                            </p>
                                        </div>
                                    </div>
                                </div>
                                <div
                                class="mb-6 w-full  ">
                                <div class="align-start flex">
                                    <div class="shrink-0">
                                    <div class="inline-block rounded-md bg-[#cccccc] p-4 text-white">
                                    <svg class="w-6 h-6 fill-white" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512"><path d="M464 256A208 208 0 1 1 48 256a208 208 0 1 1 416 0zM0 256a256 256 0 1 0 512 0A256 256 0 1 0 0 256zM232 120l0 136c0 8 4 15.5 10.7 20l96 64c11 7.4 25.9 4.4 33.3-6.7s4.4-25.9-6.7-33.3L280 243.2 280 120c0-13.3-10.7-24-24-24s-24 10.7-24 24z"/></svg>

                                    </div>
                                    </div>
                                    <div class="ml-6 grow">
                                        <p class="mb-2 font-bold">Öffnungszeiten</p>
                                        <p class="text-neutral-500">
                                            Montag bis Freitag: 09:00 - 18:00 Uhr<br>
                                            Samstag: 09:00 - 16:00 Uhr<br>
                                            Sonntag: Geschlossen
                                        </p>
                                    </div>
                                </div>
                                </div>
                                <div class="w-full ">
                                    <a  href="/contact" wire:navigate  class="inline-block bg-blue-600 text-white font-semibold py-2 px-4 rounded-md hover:bg-blue-700 transition-colors">
                                        Kontaktiere uns
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>