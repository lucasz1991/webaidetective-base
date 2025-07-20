<?php

use Illuminate\Support\Facades\Route;
use App\Providers\RouteServiceProvider;
use App\Livewire\Welcome;
use App\Livewire\Auth\Login;
use App\Livewire\Auth\Register;
use Illuminate\Support\Facades\Auth;
use App\Livewire\Dashboard;
use App\Livewire\Tutor\TutorDashboard;
use App\Livewire\Tutor\CourseList;
use App\Livewire\Tutor\Courses\CourseShow;
use App\Livewire\MessageBox;


use App\Livewire\Pages\TermsAndConditions;
use App\Livewire\Pages\PrivacyPolicy;
use App\Livewire\Pages\Imprint;
use App\Livewire\Pages\HowTo;
use App\Livewire\Pages\AboutUs;
use App\Livewire\Pages\Contact;
use App\Livewire\Pages\Faqs;
use App\Livewire\Pages\Sitemap;



use App\Livewire\Auth\RequestPasswordResetLink;
use App\Livewire\Auth\ResetPassword;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

use Illuminate\Support\Facades\Storage;
use App\Livewire\User\Absences;
use App\Livewire\User\MakeupExamRegistration;







// Allgemeine Routes für Gäste
Route::middleware('guest')->group(function () {

    Route::get('/forgot-password', RequestPasswordResetLink::class)->name('password.request');
    // Route::post('/forgot-password', [RequestPasswordResetLink::class, 'sendResetLink'])->name('password.email');
    Route::get('/reset-password/{token}', ResetPassword::class)->name('password.reset');
    // Route::post('/reset-password', [ResetPassword::class, 'reset'])->name('password.update');
    // Überschreibe die Standard-POST-Routen
    Route::post('/forgot-password', function () {
        abort(404);
    })->name('password.email');

    Route::post('/reset-password', function () {
        abort(404);
    })->name('password.update');

    Route::get('/login', Login::class)->name('login');
    Route::get('/register', Register::class)->name('register');
});

Route::middleware(['auth:sanctum', config('jetstream.auth_session'), 'verified'])->group(function () {

    // Authentifizierte Benutzer können auf die folgenden Routen zugreifen
    Route::get('/', function () {
        return redirect(RouteServiceProvider::home());
    })->name('welcome');

    // Teilnehmer Routes
    Route::middleware(['role:guest'])->prefix('user')->group(function () {
        Route::get('/dashboard', Dashboard::class)->name('dashboard');
        Route::get('/messages', MessageBox::class)->name('messages');
        Route::get('/contact', Contact::class)->name('contact');
        Route::get('/termsandconditions', TermsAndConditions::class)->name('terms');
        Route::get('/imprint', Imprint::class)->name('imprint');
        Route::get('/privacypolicy', PrivacyPolicy::class)->name('privacypolicy');
        Route::get('/sitemap', Sitemap::class)->name('sitemap');
        Route::get('/howto', HowTo::class)->name('howto');
        Route::get('/faqs', Faqs::class)->name('faqs');
        Route::get('/absences-create', Absences::class)->name('user.absences.create');
        Route::get('/makeup-exam-create', MakeupExamRegistration::class)->name('user.makeup-exam.create');
    });
    // Tutor Routes
    Route::middleware(['role:tutor'])->prefix('tutor')->group(function () {
        Route::get('/dashboard', TutorDashboard::class)->name('dashboard');
        Route::get('/tutor-courses', CourseList::class)->name('tutor.courses');
        Route::get('/tutor-course/{courseId}', CourseShow::class)->name('tutor.courses.show');
    });

});


