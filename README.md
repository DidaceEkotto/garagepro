# GaragePro — SaaS Multi-Tenant de Gestion de Garage Automobile (SYSCOHADA)

Logiciel SaaS multi-tenant conçu pour les garages automobiles au Cameroun et en Afrique francophone (devise **FCFA / XAF**, paiements **SaSPay** / Mobile Money, comptabilité conforme au **plan comptable SYSCOHADA révisé**).

## Stack technique
- **Backend** : Laravel 13, PHP 8.5, MySQL 8 / PostgreSQL
- **Front Tenant** : Inertia.js + Vue 3 (`<script setup>`) + Bootstrap 5
- **Back-office Super-Admin (`/admin`)** : Laravel Livewire avec modèle `Admin` et guard dédié `admin`
- **Files d'attente & Cache** : Redis (`predis`) + Laravel Queues
- **PDF** : `barryvdh/laravel-dompdf`
- **Tests** : PHPUnit (isolation tenant, paiements SaSPay, comptabilité SYSCOHADA, workflow atelier)

## Installation locale
```bash
cp .env.example .env
php artisan key:generate
# Renseigner DB_*, MAIL_*, SASPAY_*, ADMIN_* dans .env
php artisan migrate
php artisan db:seed --class=AdminSeeder
npm install && npm run build
```

## Tâches planifiées & Files d'attente
```bash
php artisan queue:work redis
php artisan schedule:work
```

## Tests
```bash
php artisan test
```
