<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log as Console;
use DateTime;

class HotelController extends Controller
{
    //Funcion para calcular la cantidad de dias entre dos fechas(Formato: YYYY-MM-DD)
    public function calcularDias($start, $end){
        $startDate = new DateTime($start);
        $endDate = new DateTime($end);
        $interval = $startDate->diff($endDate);
        $totalDays = $interval->days;
        return $totalDays;
    }

    public function searchHotel(Request $request) {
        //Tomamos los datos del request
        $destination = $request->input('destination');
        $arrivalDate = $request->input('arrivalDate');
        $departureDate = $request->input('departureDate');
        $qtyProduct = $request->input('qtyProduct');
        $qtyPax = $request->input('qtyPax');
        $lang = $request->input('lang');
        $others = $request->input('others');

        //To authenticate, you must send both the API Key and the X-Signature, 
        //a SHA256 hash in Hex format calculated from your API key, your secret plus current timestamps in seconds:

        //Guardamos los datos de API Key y Secret en un enviroment(.env) y los tomo de ahi
        $apiKey = env('HOTELBEDS_API_KEY');
        $secret = env('HOTELBEDS_SECRET');
        //Calculamos el signature que pide la API de HotelBeds para poder hacer el request
        $timestamp = time();
        $signature = hash('sha256', $apiKey . $secret . $timestamp);

        //Definimos los parametros que vamos a enviar en el request
        $queryParams = [
            'destinationCode' => $destination, //Codigo IATA
            'language' => $lang, //Idioma (ESP no funciona)
        ];
        //Definimos la URL con los parametros
        $url = 'https://api.test.hotelbeds.com/hotel-content-api/1.0/hotels' . '?' . http_build_query($queryParams);
        //Hacemos el llamado a Content API para traer los hoteles segun el codigo IATA que nos llega en el request
        $response = Http::withHeaders([ //Los headers que pide la API para hacer el request
            'Accept' => 'application/json',
            'Api-key' => $apiKey,
            'X-Signature' => $signature,
        ])->get($url);
              
        $apiResponse = $response->json();
        //Tomamos el primer hotel que nos devuelve el API
        $hotel1 = $apiResponse['hotels'][0];    
        //Definimos el body que le vamos a pasar a la API para que nos devuelva los datos del hotel
        //siguiendo el formato de la documentacion
        $requestData = [
            'stay' => [
                'checkIn' => $arrivalDate,
                'checkOut' => $departureDate,
            ],
            'occupancies' => [
                [
                    'rooms' => $qtyProduct,
                    'adults' => $qtyPax,
                    'children' => 0,
                ]
            ],
            'hotels' => [
                'hotel' => [
                    $hotel1['code'],
                ]
            ],
            ];
        //Definimos un nuevo URL para hacer un nuevo request a la API y traer los datos del hotel
        $url = 'https://api.test.hotelbeds.com/hotel-api/1.0/hotels';
        $response = Http::withHeaders([
            'Accept' => 'application/json',
            'Api-key' => $apiKey,
            'X-Signature' => $signature,
            'Content-Type' => 'application/json',
            'Accept-Encoding' => 'gzip',
        ])->post($url, $requestData);

        $apiResponse = $response->json();
        //Tomamos el hotel que nos devuelve el API para facilitar la lectura del codigo
        $hotel = $apiResponse['hotels']['hotels'][0];
        //Una vez obtenidos todos los datos del hotel, comenzamos a transformar la respuesta al formato que necesitamos
        //Puede que no todos los datos correspondan al formato RS, intenté que correspondan la mayor cantidad posible
        //Pero habia muchos datos que no estaban en la respuesta del API o no estaban en el formato que necesitabamos
        //Por lo que los deje en "N/A" para que se vea que faltan esos datos
        //O algunos datos si estan asignados pero no son tal cual el que pide el formato RS, pero se asemeja
        $newFormatResponse = [
            'id' => $hotel['code'],
            'hotel_name' => $hotel['name'],
            'title' => $hotel['name'],
            'description' => $hotel1['description']['content'],
            'categoryId' => $hotel['categoryCode'],
            'productId' => $hotel['code'],
            'view_hotel' => 0,
            'destination' => $hotel['destinationCode'],
            'dateIni' => $apiResponse['hotels']['checkIn'],
            'dateEnd' => $apiResponse['hotels']['checkOut'],
            'totaldays' => $this->calcularDias($apiResponse['hotels']['checkIn'], $apiResponse['hotels']['checkOut']),
            'logo' => 'N/A',
            'logo_hotel' => 'N/A',
            'coordinates' => [
                'longitude' => (float) $hotel['longitude'],
                'latitude' => (float) $hotel['latitude'],
            ],
            'mapsdescript' => "",
            'zonename' => $hotel1['address']['content'],
            'additionalInfo' => [
                'location' => $hotel1['address']['content'],
                'city' => $hotel1['city']['content'],
                'country' => $hotel1['countryCode'],
                'zipcode' => $hotel1['postalCode'],
                'numberOfFloors' => 'N/A',
                'numberOfrooms' => count($hotel['rooms']),
                'constructionYear' => 'N/A',
                'accommodationTypeCode' => $hotel1['accommodationTypeCode'],
                'numberOfRestaurants' => 'N/A',
                'acceptChildren' => 'N/A',
                'acceptPets' => 'N/A',
                'cover_image' => 'N/A',
                'suitableForSmoker' => 'N/A',
                'starRating' => $hotel['categoryName'],
                'reserveMultiHab' => true, //true or false
                'TimeInformation' => [
                    'CheckIn' => $apiResponse['hotels']['checkIn'],//pide horario no fecha pero el API no devuelve
                    'CheckOut' => $apiResponse['hotels']['checkOut'],//los horarios de checkin y checkout, ni datos 
                ],                                                 // para calcular estos horarios.     
                'images' => [],
                'roomsQty' => count($hotel['rooms']),
                'rooms' => [],
            ],
        ];
        // Recorremos las habitaciones para asignarlas al array de rooms del response
        foreach ($hotel['rooms'] as $room) {
            $newFormatRoom = [
                'providerLogo' => "https://octopus-apis.com/img/std_logos/hotelbeds.jpg",
                'hotelCode' => $hotel['code'],
                'description' => 'N/A',
                'roomType' => $room['name'],
                'roomId' => $room['code'],
                'roomCode' => $room['code'],
                'bookParam' => $room['rates'][0]['rateKey'],
                'rooms' => $room['rates'][0]['rooms'],
                'maxPax' => $room['rates'][0]['adults'] + $room['rates'][0]['children'],
                'alloutment' => $room['rates'][0]['allotment'],
                'rateKey' => $room['rates'][0]['rateKey'],
                'rateClass' => $room['rates'][0]['rateClass'],
                'rateType' => $room['rates'][0]['rateType'],
                'optionNightsNetTotal' => 'N/A',
                'netPrice' => $room['rates'][0]['net'],
                'boardName' => $room['rates'][0]['boardName'],
                'optionNightsTotal' => 'N/A', 
                'roomamenities' => [], //'N/A'
                'images' => [], //'N/A'
                'cancellation_policy' => [],
            ];
            // Recorremos las politicas de cancelacion para asignarlas al array de cancellation_policy de cada room
            foreach ($room['rates'][0]['cancellationPolicies'] as $policy) {
                $cancellationPolicy = [
                    'descript_polecy' => 'N/A',
                    'amount' => $policy['amount'],
                    'from' => $policy['from'],
                    'PaymentLimitDay' => null,
                ];

                $newFormatRoom['cancellation_policy'][] = $cancellationPolicy;
            }

            //En el caso que hubiera images en la respuesta del API, aqui deberiamos hacer otro foreach
            //para recorrerlas y agregarlas al array de images del room que estoy recorriendo.

            $newFormatResponse['additionalInfo']['rooms'][] = $newFormatRoom;
        }

        return $newFormatResponse;
    }

}