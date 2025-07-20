<?php

namespace App\Policies;

use App\Models\Product;
use App\Models\User;

class ProductPolicy
{
    /**
     * Prüft, ob der Benutzer ein Produkt anzeigen darf.
     */
    public function view(User $user, Product $product)
    {
        // Verkäufer oder Admins dürfen Produkte anzeigen
        return $user->role === 'admin' || $user->id === $product->seller_id;
    }

    /**
     * Prüft, ob der Benutzer ein Produkt erstellen darf.
     */
    public function create(User $user)
    {
        return $user->role === 'seller'; // Nur Verkäufer können Produkte erstellen
    }

    /**
     * Prüft, ob der Benutzer ein Produkt aktualisieren darf.
     */
    public function update(User $user, Product $product)
    {
        // Nur der Besitzer des Produkts oder ein Admin darf es aktualisieren
        return $user->role === 'admin' || $user->id === $product->seller_id;
    }

    /**
     * Prüft, ob der Benutzer ein Produkt löschen darf.
     */
    public function delete(User $user, Product $product)
    {
        // Nur der Besitzer des Produkts oder ein Admin darf es löschen,
        // und das Produkt muss den Status "Entwurf" haben
        return ($user->role === 'admin' || $user->id === $product->seller_id)
            && $product->status === 'draft';
    }
}

