<div>
    <section class=" h-[400px] z-10 relative">
        <div id="map" class="relative h-[400px] overflow-hidden bg-cover bg-[50%] bg-no-repeat">
                <!-- Das Overlay -->
            <div style="
                position: absolute;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                pointer-events: none; 
                background: var(--primary-color); opacity:0.2;
            ">
            </div>
            <iframe 
                src="https://www.google.com/maps/embed?pb=!1m10!1m8!1m3!1d75830.19174830109!2d9.9176227!3d53.5632388!3m2!1i1024!2i768!4f13.1!5e0!3m2!1sde!2sde!4v1747545555115!5m2!1sde!2sde" 
                data-cookieconsent="marketing"
                width="100%" height="100%" style="border:0;" allowfullscreen="" loading="lazy" referrerpolicy="no-referrer-when-downgrade">
            </iframe>
        </div>
        <div class=" max-w-7xl px-6 md:px-12 mx-auto h-0">
            <div
                x-init="$nextTick(() => {
                        console.log('resize init');
                        document.querySelectorAll('.calcTopPadding').forEach(targetElement => {
                            targetElement.style.paddingTop = $el.offsetHeight + 'px';
                        });
                    })"
                    x-effect="$nextTick(() => {
                        console.log('resize init');
                        document.querySelectorAll('.calcTopPadding').forEach(targetElement => {
                            targetElement.style.paddingTop = $el.offsetHeight + 'px';
                        });
                    })"
                    x-resize="$el => {
                        console.log('resize');
                        document.querySelectorAll('.calcTopPadding').forEach(targetElement => {
                            targetElement.style.paddingTop = $height + 'px';
                        });
                    }"
            class="block rounded-lg bg-[hsla(0,0%,100%,0.8)] px-6 py-12 shadow-[0_2px_15px_-3px_rgba(0,0,0,0.07),0_10px_20px_-2px_rgba(0,0,0,0.04)]  md:py-16 md:px-12 -mt-[100px] backdrop-blur-[30px] border border-gray-300 z-30">
            <div class="flex max-lg:flex-wrap">
                
            <div class="mb-12 w-full md:w-4/12  px-6 md:px-3 lg:px-6">
                <a href="/" class="inline-flex items-center justify-center text-blue-600 mb-4">
                    <span class="sr-only">Home</span>
                    <x-application-logo/>
                </a>
                <h2 class="text-2xl font-semibold text-gray-800 mb-4">Kontakt</h2>
                <p class="text-gray-600 leading-relaxed">
                Vielen Dank für Ihr Interesse an CBW Schulnetz! Wenn Sie Fragen zu unseren Bildungsangeboten haben, Unterstützung bei der Nutzung unserer Plattform benötigen oder weitere Informationen rund um unsere Services wünschen, stehen wir Ihnen gerne zur Verfügung.
                </p>
            </div>
                <div class="flex items-center grow-0 basis-auto">
                    <div class="flex flex-wrap h-min justify-center">
                        <div class="mb-12 w-full shrink-0 grow-0 basis-auto md:w-7/12 md:px-3 ">
                            <div class="flex items-start">
                                <div class="shrink-0">
                                    <div class="inline-block rounded-md bg-[#cccccc] p-4 text-white">
                                        <svg class="w-6 h-6 fill-white" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512"><path d="M256 48C141.1 48 48 141.1 48 256l0 40c0 13.3-10.7 24-24 24s-24-10.7-24-24l0-40C0 114.6 114.6 0 256 0S512 114.6 512 256l0 144.1c0 48.6-39.4 88-88.1 88L313.6 488c-8.3 14.3-23.8 24-41.6 24l-32 0c-26.5 0-48-21.5-48-48s21.5-48 48-48l32 0c17.8 0 33.3 9.7 41.6 24l110.4 .1c22.1 0 40-17.9 40-40L464 256c0-114.9-93.1-208-208-208zM144 208l16 0c17.7 0 32 14.3 32 32l0 112c0 17.7-14.3 32-32 32l-16 0c-35.3 0-64-28.7-64-64l0-48c0-35.3 28.7-64 64-64zm224 0c35.3 0 64 28.7 64 64l0 48c0 35.3-28.7 64-64 64l-16 0c-17.7 0-32-14.3-32-32l0-112c0-17.7 14.3-32 32-32l16 0z"/></svg>
                                    </div>
                                </div>
                                <div class="ml-6 grow">
                                    <p class="mb-2 font-bold ">
                                        Support
                                    </p>
                                    <p class="text-sm text-neutral-500">
                                        info@cbw-weiterbildung.de
                                    </p>
                                </div>
                            </div>
                        </div>
                        <div
                        class="mb-12 w-full shrink-0 grow-0 basis-auto md:mb-0 md:w-5/12 md:px-3 ">
                        <div class="align-start flex">
                            <div class="shrink-0">
                            <div class="inline-block rounded-md bg-[#cccccc] p-4 text-white">
                            <svg class="w-6 h-6 fill-white"  xmlns="http://www.w3.org/2000/svg" viewBox="0 0 448 512"><path d="M352 224c53 0 96-43 96-96s-43-96-96-96s-96 43-96 96c0 4 .2 8 .7 11.9l-94.1 47C145.4 170.2 121.9 160 96 160c-53 0-96 43-96 96s43 96 96 96c25.9 0 49.4-10.2 66.6-26.9l94.1 47c-.5 3.9-.7 7.8-.7 11.9c0 53 43 96 96 96s96-43 96-96s-43-96-96-96c-25.9 0-49.4 10.2-66.6 26.9l-94.1-47c.5-3.9 .7-7.8 .7-11.9s-.2-8-.7-11.9l94.1-47C302.6 213.8 326.1 224 352 224z"/></svg>
                            </div>
                            </div>
                            <div class="ml-6 grow">
                            <ul class="flex space-x-5">
                                <li>
                                <a href='https://www.facebook.com' target="_blank">
                                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" class="fill-[#5796fc] hover:fill-[#0866ff] w-10 h-10"
                                    viewBox="0 0 24 24">
                                    <path fill-rule="evenodd"
                                        d="M19 3H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h7v-7h-2v-3h2V8.5A3.5 3.5 0 0 1 15.5 5H18v3h-2a1 1 0 0 0-1 1v2h3v3h-3v7h4a2 2 0 0 0 2-2V5a2 2 0 0 0-2-2z"
                                        clip-rule="evenodd" />
                                    </svg>
                                    <span class="sr-only">Facebook Link</span>
                                </a>
                                </li>
                                <li>
                                <a href='https://www.instagram.com' target="_blank">
                                    <svg xmlns="http://www.w3.org/2000/svg" fill="none"
                                    class="fill-[#fc7dcb] hover:fill-[#ff05a0] w-10 h-10" viewBox="0 0 24 24">
                                    <path
                                        d="M12 9.3a2.7 2.7 0 1 0 0 5.4 2.7 2.7 0 0 0 0-5.4Zm0-1.8a4.5 4.5 0 1 1 0 9 4.5 4.5 0 0 1 0-9Zm5.85-.225a1.125 1.125 0 1 1-2.25 0 1.125 1.125 0 0 1 2.25 0ZM12 4.8c-2.227 0-2.59.006-3.626.052-.706.034-1.18.128-1.618.299a2.59 2.59 0 0 0-.972.633 2.601 2.601 0 0 0-.634.972c-.17.44-.265.913-.298 1.618C4.805 9.367 4.8 9.714 4.8 12c0 2.227.006 2.59.052 3.626.034.705.128 1.18.298 1.617.153.392.333.674.632.972.303.303.585.484.972.633.445.172.918.267 1.62.3.993.047 1.34.052 3.626.052 2.227 0 2.59-.006 3.626-.052.704-.034 1.178-.128 1.617-.298.39-.152.674-.333.972-.632.304-.303.485-.585.634-.972.171-.444.266-.918.299-1.62.047-.993.052-1.34.052-3.626 0-2.227-.006-2.59-.052-3.626-.034-.704-.128-1.18-.299-1.618a2.619 2.619 0 0 0-.633-.972 2.595 2.595 0 0 0-.972-.634c-.44-.17-.914-.265-1.618-.298-.993-.047-1.34-.052-3.626-.052ZM12 3c2.445 0 2.75.009 3.71.054.958.045 1.61.195 2.185.419A4.388 4.388 0 0 1 19.49 4.51c.457.45.812.994 1.038 1.595.222.573.373 1.227.418 2.185.042.96.054 1.265.054 3.71 0 2.445-.009 2.75-.054 3.71-.045.958-.196 1.61-.419 2.185a4.395 4.395 0 0 1-1.037 1.595 4.44 4.44 0 0 1-1.595 1.038c-.573.222-1.227.373-2.185.418-.96.042-1.265.054-3.71.054-2.445 0-2.75-.009-3.71-.054-.958-.045-1.61-.196-2.185-.419A4.402 4.402 0 0 1 4.51 19.49a4.414 4.414 0 0 1-1.037-1.595c-.224-.573-.374-1.227-.419-2.185C3.012 14.75 3 14.445 3 12c0-2.445.009-2.75.054-3.71s.195-1.61.419-2.185A4.392 4.392 0 0 1 4.51 4.51c.45-.458.994-.812 1.595-1.037.574-.224 1.226-.374 2.185-.419C9.25 3.012 9.555 3 12 3Z" />
                                    </svg>
                                    <span class="sr-only">Instagram Link</span>
                                </a>
                                </li>
                                
                            </ul>
                            </div>
                        </div>
                        </div>
                    </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
    <section class="bg-white z-10">
        <div class="lg:grid lg:min-h-[70vh] lg:grid-cols-12 ">
            <!-- Linker Bereich mit Bild -->
            <div wire:ignore class="calcTopPadding relative flex h-80 items-center justify-end bg-gray-900 lg:col-span-5 lg:h-full xl:col-span-6">
                
                <img
                    alt=""
                    src="{{ asset('site-images/home-Slider_-_Studenten.jpg') }}"
                    class=" absolute inset-0 h-full w-full object-cover opacity-80"
                />
                <!-- Overlay -->
                <div class="absolute inset-0 bg-primary-900/40" ></div>
                <div class="hidden lg:relative lg:block lg:p-12">

                    <h2 class="mt-6 text-2xl font-bold sm:text-3xl md:text-4xl text-white">
                        Kontaktiere uns!
                    </h2>

                    <p class="mt-4 text-xl font-bold leading-relaxed text-white">
                        Egal, ob du Fragen, Vorschläge oder Wünsche hast – wir sind für dich da. Schreib uns einfach!
                    </p>
                </div>
            </div>

            <!-- Rechter Bereich mit Kontaktformular -->
            <div
                class="flex items-center justify-center px-8 py-8 sm:px-12 lg:col-span-7 lg:px-16 lg:py-12 xl:col-span-6"
            >
            <div class="max-w-xl lg:max-w-3xl" >
                    <div wire:ignore class="w-full calcTopPadding max-lg-pt-none"></div>
                    <div class="relative  block lg:hidden">
                        

                        <h1 class="mt-2 text-2xl font-bold text-gray-900 sm:text-3xl md:text-4xl">
                            Kontaktiere uns!
                        </h1>

                        <p class="mt-4 text-xl font-bold leading-relaxed text-gray-500">
                            Egal, ob du Fragen, Vorschläge oder Wünsche hast – wir sind für dich da. Schreib uns einfach!
                        </p>
                    </div>

                    <!-- Kontaktformular -->
                    <div class="mt-8">
                     
                        @if (session()->has('success'))
                            <div class="bg-green-100 text-green-700 p-4 rounded mb-6">
                                {{ session('success') }}
                            </div>
                        @endif   

                    <div class="grid grid-cols-2  gap-4">
                        <div class="col-span-2">
                            <x-label for="name" value="Dein Name" />
                            <x-input wire:model="name"  id="name" class="block mt-1 w-full" type="text" name="name" required placeholder="Max Mustermann" />
                            @error('name')
                                <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <div class="col-span-1">
                            <x-label for="email" value="Deine E-Mail" />
                            <x-input wire:model="email"  id="email" class="block mt-1 w-full" type="email" name="email" required placeholder="name@beispiel.de" />
                            @error('email')
                                <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <div class="col-span-1">
                            <x-label for="subject" value="Betreff" />
                            <x-input wire:model="subject"  id="subject" class="block mt-1 w-full" type="text" name="subject" required placeholder="Worum geht es?" />
                            @error('subject')
                                <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <div class="col-span-2 ">
                            <x-label for="message" value="Deine Nachricht" />
                            <textarea wire:model="message"  id="message" name="message" rows="5" required
                                class="block mt-1 w-full border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500"
                                placeholder="Schreibe deine Nachricht hier..."></textarea>
                            @error('message')
                                <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <div class="col-span-2 flex items-center justify-end">
                            <button 
                                wire:click="send"
                                class="inline-block shrink-0 rounded-md border border-blue-600 bg-blue-600 px-12 py-3 text-sm font-medium text-white transition hover:bg-transparent hover:text-blue-600 focus:outline-none focus:ring active:text-blue-500">
                                Nachricht senden
                            </button>
                        </div>
                    </div>

                        
                    </div>
                </div>
    </div>
        </div>
    </section>
</div>
