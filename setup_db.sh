#!/bin/bash
cp .env.example .env
sed -i 's/^DB_CONNECTION=.*/DB_CONNECTION=sqlite/' .env
sed -i 's/^DB_DATABASE=.*/# DB_DATABASE=/' .env
sed -i 's/^# DB_DATABASE=.*/DB_DATABASE='$(pwd | sed 's/\//\\\//g')'\/database\/database.sqlite/' .env
touch database/database.sqlite
php artisan key:generate
php artisan migrate:fresh --seed
