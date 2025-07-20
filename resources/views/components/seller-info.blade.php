@props(['shelfRental', 'isFollowing'])

<div class="flex items-start justify-start">
    <div class="flex items-center space-x-3">
        <!-- Profilbild -->
        <img 
            class="h-10 aspect-square rounded-full object-cover" 
            src="{{ $shelfRental->customer->user->profile_photo_url }}" 
            alt="{{ $shelfRental->customer->user->name }}" 
        />

        <!-- Name und Beitrittsdatum -->
        <div>
            <!-- Name -->
            <p class="text-sm text-gray-800 font-semibold">
                {{ $shelfRental->customer->user->name ?? 'Unbekannt' }}
            </p>
            
            <!-- Beitrittsdatum -->
            <p class="text-xs text-gray-500">
                Mitglied seit {{ $shelfRental->customer->user->created_at->format('d.m.Y') }}
            </p>
        </div>
    </div>
    @auth
    <div class="my-2 ml-3">
        <!-- Folgen-Button -->
        <button 
            class="transition-all duration-100 flex items-center border shadow text-gray-600 {{ $isFollowing ? 'border-red-300 bg-red-100 hover:bg-red-200' : 'border-gray-300 bg-gray-100 hover:bg-green-200' }}  text-sm font-medium py-1 px-2 rounded-full transition duration-300"
            x-data="{ isClicked: false }" 
                @click="isClicked = true; setTimeout(() => isClicked = false, 100)"
                style="transform:scale(1);"
                :style="isClicked ? 'transform:scale(0.7);' : ''"
            wire:click="toggleFollow"
        >
            <!-- Icon -->
            
            <svg xmlns="http://www.w3.org/2000/svg"  class="mr-2 h-5 w-5"  fill="currentColor" viewBox="0 0 64 64"><path d="M46.78448,43.128V29.7A14.80554,14.80554,0,0,0,35.40611,15.312a4.44427,4.44427,0,1,0-7.85886-2.83962A4.36544,4.36544,0,0,0,28.58714,15.312,14.81625,14.81625,0,0,0,17.20877,29.7V42.49811c.25841-.00465.93828-.00348,1.16987,0l22.4067,0a.99986.99986,0,1,1,0,1.99971H16.06892l-2.79959,3.35951a2.16207,2.16207,0,0,0,1.64981,3.52948l34.15505,0a2.16107,2.16107,0,0,0,1.64965-3.52956ZM31.99663,14.912a2.44476,2.44476,0,0,1,.00007-4.88931A2.44476,2.44476,0,0,1,31.99663,14.912Z"/><path d="M31.99663,57.136a6.72137,6.72137,0,0,0,6.02916-3.74947H25.96746A6.72138,6.72138,0,0,0,31.99663,57.136Z"/><path d="M12.99935,18.74151a9.30311,9.30311,0,0,1,5.66917-4.35936.99937.99937,0,0,0-.51994-1.92969,11.41176,11.41176,0,0,0-8.0187,13.888,1.00306,1.00306,0,0,0,1.93959-.51A9.25183,9.25183,0,0,1,12.99935,18.74151Z"/><path d="M45.84462,12.45244a.99936.99936,0,0,0-.51983,1.92973,9.34628,9.34628,0,0,1,6.599,11.43834,1.0041,1.0041,0,0,0,1.93972.51989A11.3606,11.3606,0,0,0,45.84462,12.45244Z"/><path d="M17.18876,8.8329a.99956.99956,0,0,0-.52-1.92969,17.11977,17.11977,0,0,0-12.08817,20.927,1.00433,1.00433,0,0,0,1.9397-.51994A15.10217,15.10217,0,0,1,17.18876,8.8329Z"/><path d="M57.693,14.86206A16.983,16.983,0,0,0,47.32444,6.90318a.99954.99954,0,0,0-.51988,1.92974A15.16274,15.16274,0,0,1,57.4728,27.31037a1.0044,1.0044,0,0,0,1.93988.51976A16.9609,16.9609,0,0,0,57.693,14.86206Z"/></svg>
            <!-- Text -->
            <span>
                {{ $isFollowing ? 'Entfolgen' : 'Folgen' }}
            </span>
        </button>
    </div>
    @endauth
</div>
