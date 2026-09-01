<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    @vite('resources/css/app.css')
    

    <title>{{env('APP_NAME')}}</title>
</head>

<body class="text-gray-800 bg-white dark:bg-gray-700 dark:text-gray-100 overflow-hidden">
    <div id="app"></div>
    @vite('resources/js/app.ts')
</body>



</html>