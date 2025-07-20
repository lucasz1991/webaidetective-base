<footer x-data="{ screenWidth: window.innerWidth }" x-resize="screenWidth = $width"  class="footer bg-cover bg-center border-t border-gray-300  bg-white  ">
    <div class="bg-blue-800  tracking-wide px-8 py-12 ">

        <div class="container mx-auto">
            <div class="   grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 lg:grid-cols-4 gap-x-6 gap-y-10">
                <div>
                    <a href='/' class="block h-auto   bg-white w-max p-2 rounded-lg">
                        <x-application-logo  />
                    </a>   
                </div>
                @auth
                <div x-data="{ open: false }">
                    <h4 class="text-white font-semibold text-lg relative max-sm:cursor-pointer" @click="open = !open">
                            Funktionen 
                        <svg
                        xmlns="http://www.w3.org/2000/svg" width="16px" height="16px":class="open ? 'transform rotate-180' : ''"
                        class="sm:hidden  transition-all ease-in duration-200 absolute right-0 top-1 fill-[#d6d6d6]" viewBox="0 0 24 24">
                            <path
                            d="M12 16a1 1 0 0 1-.71-.29l-6-6a1 1 0 0 1 1.42-1.42l5.29 5.3 5.29-5.29a1 1 0 0 1 1.41 1.41l-6 6a1 1 0 0 1-.7.29z"
                            data-name="16" data-original="#000000"></path>
                        </svg>
                    </h4>
                    <div x-show="open || screenWidth >= 768" x-collapse.duration.1000ms @click.away="open = false" >
                        <ul class="mt-6 space-y-5">
                            
                           
                            
                        </ul>
                    </div>
                </div>
                <div x-data="{ open: false }">
                    <h4 class="text-white font-semibold text-lg relative max-sm:cursor-pointer" @click="open = !open">Unternehmen <svg
                        xmlns="http://www.w3.org/2000/svg" width="16px" height="16px"
                        :class="open ? 'transform rotate-180' : ''"
                            class="sm:hidden absolute transition-all ease-in duration-200 absolute right-0 top-1 fill-[#d6d6d6]" viewBox="0 0 24 24">
                        <path
                        d="M12 16a1 1 0 0 1-.71-.29l-6-6a1 1 0 0 1 1.42-1.42l5.29 5.3 5.29-5.29a1 1 0 0 1 1.41 1.41l-6 6a1 1 0 0 1-.7.29z"
                        data-name="16" data-original="#000000"></path>
                    </svg>
                    </h4>
                    <div x-show="open || screenWidth >= 768" x-collapse.duration.1000ms @click.away="open = false" >
                        <ul class="space-y-5 mt-6">
                            <li>
                            <a href="/aboutus" wire:navigate  class='hover:text-white text-white text-sm'>Über uns</a>
                            </li>
                        </ul>
                    </div>
                </div>
                <div x-data="{ open: false }" x-intersect.full.once="setTimeout(() => {open = true;}, 1000);">
                    <h4 class="text-white font-semibold text-lg relative max-sm:cursor-pointer" @click="open = !open">Additional 
                        <svg xmlns="http://www.w3.org/2000/svg" width="16px" height="16px"
                            :class="open ? 'transform rotate-180' : ''"
                            class="sm:hidden absolute transition-all ease-in duration-200 right-0 top-1 fill-[#d6d6d6]" viewBox="0 0 24 24">
                            <path d="M12 16a1 1 0 0 1-.71-.29l-6-6a1 1 0 0 1 1.42-1.42l5.29 5.3 5.29-5.29a1 1 0 0 1 1.41 1.41l-6 6a1 1 0 0 1-.7.29z"
                            data-name="16" data-original="#000000">
                            </path>
                        </svg>
                    </h4>
                    <div x-show="open || screenWidth >= 768" x-collapse.duration.1000ms @click.away="open = false"  >
                        <ul class="space-y-5 mt-6 ">
                            <li>
                            <a href='/faqs' wire:navigate  class='hover:text-white text-white text-sm'>FAQ</a>
                            </li>
                            <li>
                            <a  href="/contact" wire:navigate  class='hover:text-white text-white text-sm'>Kontakt</a>
                            </li>
                        </ul>
                    </div>
                </div>
                @endauth  
            </div>
            <hr class="container mx-auto  my-10 border-gray-400" />
            <div class="container mx-auto  flex flex-wrap max-md:flex-col gap-4">
                <div>
                    <ul class="md:flex md:space-x-6 max-md:space-y-2 max-sm:grid max-sm:grid-flow-row-dense max-sm:grid-cols-3 max-sm:grid-rows-3">
                        
                        <li class="max-sm:col-span-2">
                            <a href="/privacypolicy" wire:navigate class='hover:text-white text-white text-sm '>Datenschutz</a>
                        </li>
                        <li class="max-sm:col-span-2">
                            <a href="/imprint" wire:navigate class='hover:text-white text-white text-sm '>Impressum</a>
                        </li>
                        <li class="max-sm:inline-flex max-sm:place-self-end max-sm:w-max" >
                            <a class=""  href='' target="_blank">
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" class="fill-secondary hover:fill-secondary w-7 h-7"
                                viewBox="0 0 24 24">
                                <path fill-rule="evenodd"
                                    d="M19 3H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h7v-7h-2v-3h2V8.5A3.5 3.5 0 0 1 15.5 5H18v3h-2a1 1 0 0 0-1 1v2h3v3h-3v7h4a2 2 0 0 0 2-2V5a2 2 0 0 0-2-2z"
                                    clip-rule="evenodd" />
                                </svg>
                                <span class="sr-only">Facebook Link</span>
                            </a>
                        </li>
                        <li class="max-sm:inline-flex max-sm:place-self-end max-sm:w-max" >
                            <a class="" href='' target="_blank">
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none"
                                class="fill-secondary hover:fill-secondary w-7 h-7" viewBox="0 0 24 24">
                                <path
                                    d="M12 9.3a2.7 2.7 0 1 0 0 5.4 2.7 2.7 0 0 0 0-5.4Zm0-1.8a4.5 4.5 0 1 1 0 9 4.5 4.5 0 0 1 0-9Zm5.85-.225a1.125 1.125 0 1 1-2.25 0 1.125 1.125 0 0 1 2.25 0ZM12 4.8c-2.227 0-2.59.006-3.626.052-.706.034-1.18.128-1.618.299a2.59 2.59 0 0 0-.972.633 2.601 2.601 0 0 0-.634.972c-.17.44-.265.913-.298 1.618C4.805 9.367 4.8 9.714 4.8 12c0 2.227.006 2.59.052 3.626.034.705.128 1.18.298 1.617.153.392.333.674.632.972.303.303.585.484.972.633.445.172.918.267 1.62.3.993.047 1.34.052 3.626.052 2.227 0 2.59-.006 3.626-.052.704-.034 1.178-.128 1.617-.298.39-.152.674-.333.972-.632.304-.303.485-.585.634-.972.171-.444.266-.918.299-1.62.047-.993.052-1.34.052-3.626 0-2.227-.006-2.59-.052-3.626-.034-.704-.128-1.18-.299-1.618a2.619 2.619 0 0 0-.633-.972 2.595 2.595 0 0 0-.972-.634c-.44-.17-.914-.265-1.618-.298-.993-.047-1.34-.052-3.626-.052ZM12 3c2.445 0 2.75.009 3.71.054.958.045 1.61.195 2.185.419A4.388 4.388 0 0 1 19.49 4.51c.457.45.812.994 1.038 1.595.222.573.373 1.227.418 2.185.042.96.054 1.265.054 3.71 0 2.445-.009 2.75-.054 3.71-.045.958-.196 1.61-.419 2.185a4.395 4.395 0 0 1-1.037 1.595 4.44 4.44 0 0 1-1.595 1.038c-.573.222-1.227.373-2.185.418-.96.042-1.265.054-3.71.054-2.445 0-2.75-.009-3.71-.054-.958-.045-1.61-.196-2.185-.419A4.402 4.402 0 0 1 4.51 19.49a4.414 4.414 0 0 1-1.037-1.595c-.224-.573-.374-1.227-.419-2.185C3.012 14.75 3 14.445 3 12c0-2.445.009-2.75.054-3.71s.195-1.61.419-2.185A4.392 4.392 0 0 1 4.51 4.51c.45-.458.994-.812 1.595-1.037.574-.224 1.226-.374 2.185-.419C9.25 3.012 9.555 3 12 3Z" />
                                </svg>
                                <span class="sr-only">Instagram Link</span>
                            </a>
                        </li> 
                    </ul>
                </div>

                <p class='text-white text-sm md:ml-auto'>&copy; {{ date("Y") }} CBW College Berufliche Weiterbildung GmbH. Alle Rechte vorbehalten.</p>
            </div>
        </div>
    </div>
</footer>
