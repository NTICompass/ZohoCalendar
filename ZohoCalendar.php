<?php
class ZohoCalendar {
    // Configure this at https://api-console.zoho.com/
    private $zohoClient = [
      'client_id' => 'your-public-api-id',
      'client_secret' => 'your-private-api-id',
      'redirect_url' => 'your-redirect-url'
    ];
    
    const DEBUG = FALSE;
    
    // https://www.zoho.com/accounts/protocol/oauth/web-apps/authorization.html
    // https://www.zoho.com/accounts/protocol/oauth/use-access-token.html
    // https://www.zoho.com/calendar/help/api/events-api.html
    public function getAuthURL() {
        return 'https://accounts.zoho.com/oauth/v2/auth?' . http_build_query([
            'client_id' => $this->zohoClient->client_id,
            'response_type' => 'code',
            'redirect_uri' => $this->zohoClient->redirect_url,
            'scope' => 'ZohoCalendar.calendar.ALL,ZohoCalendar.event.ALL',
            'access_type' => 'offline',
            'prompt' => 'consent' # Force regenerating refresh_token
        ]);
    }
    
    public function getCalendarID($authToken) {
        $calendars = $this->_curlRequest('https://calendar.zoho.com/api/v1/calendars', [
            'category' => 'own'
        ], $authToken, 'GET');

        return !isset($calendars['error']) ? $calendars['calendars'] : NULL;
    }

    public function createCalendar($authToken) {
        $calendar = $this->_curlRequest('https://calendar.zoho.com/api/v1/calendars', [
            'name' => 'A new calendar',
            'color' => '#668CB3',
            'textcolor' => '#000000',
            'include_infreebusy' => true,
            'timezone' => 'America/New_York',
            'description' => 'Calendar created from the API',
            #'visibility' => 'true',
            #'private' => 'true',
            #'public' => 'false'
        ], $authToken, 'POST', 'calendarData');

        return !isset($calendar['error']) ? $calendar['calendars'] : NULL;
    }
    
    public function login($code) {
        return $this->_curlRequest('https://accounts.zoho.com/oauth/v2/token', [
            'code' => $code,
            'client_id' => $this->zohoClient->client_id,
            'client_secret' => $this->zohoClient->client_secret,
            'grant_type' => 'authorization_code',
            'redirect_uri' => $this->zohoClient->redirect_url, # This needs to be here for some reason
        ]);
    }
    
    public function refreshToken($refreshToken) {
        return $this->_curlRequest('https://accounts.zoho.com/oauth/v2/token', [
            'refresh_token' => $refreshToken,
            'client_id' => $this->zohoClient->client_id,
            'client_secret' => $this->zohoClient->client_secret,
            'grant_type' => 'refresh_token',
            'redirect_uri' => $this->zohoClient->redirect_url,
        ]);
    }
    
    public function logout($refreshToken) {
        return $this->_curlRequest('https://accounts.zoho.com/oauth/v2/token/revoke', [
            'token' => $refreshToken
        ]);
    }
    
    public function addEvent($authToken, $calendarID, $title, $dateInfo, $address, $organizer) {
        // The API docs say the "estatus" field is required... it's not
        // https://www.zoho.com/calendar/help/api/post-create-event.html
        $event = $this->_curlRequest("https://calendar.zoho.com/api/v1/calendars/{$calendarID}/events", [
            'title' => $title,
            'dateandtime' => [
                'timezone' => 'America/New_York',
                'start' => $dateInfo['start'],
                'end' => $dateInfo['end']
            ],
            'organizer' => $organizer,
            'location' => $address
        ], $authToken, 'POST', 'eventdata');

        return !isset($event['error']) ? $event['events'] : NULL;
    }

    public function getEvent($authToken, $calendarID, $eventID) {
        $event = $this->_curlRequest("https://calendar.zoho.com/api/v1/calendars/{$calendarID}/events/{$eventID}", NULL, $authToken, 'GET');
        return !isset($event['error']) ? $event['events'] : NULL;
    }
    
    public function updateEvent($authToken, $calendarID, $eventID, $title, $dateInfo, $address, $organizer) {
        // In order to update events, we need *both* the eventID and the event's etag
        // Since I am not storing the etag, I'll have to make a request to get it before doing the PUT
        // Kinda wish this was in the API docs: https://www.zoho.com/calendar/help/api/put-update-event.html
        $eventData = $this->getEvent($authToken, $calendarID, $eventID);
        if (is_null($eventData)) {
            return NULL;
        }

        $event = $this->_curlRequest("https://calendar.zoho.com/api/v1/calendars/{$calendarID}/events/{$eventID}", [
            'etag' => $eventData[0]['etag'],
            'title' => $title,
            'dateandtime' => [
                'timezone' => 'America/New_York',
                'start' => $dateInfo['start'],
                'end' => $dateInfo['end']
            ],
            'organizer' => $organizer,
            'location' => $address
        ], $authToken, 'PUT', 'eventdata');

        return !isset($event['error']) ? $event['events'] : NULL;
    }
    
    public function deleteEvent($authToken, $calendarID, $eventID) {
        $eventData = $this->getEvent($authToken, $calendarID, $eventID);
        if (is_null($eventData)) {
            return NULL;
        }

        $event = $this->_curlRequest("https://calendar.zoho.com/api/v1/calendars/{$calendarID}/events/{$eventID}", [
            'etag' => $eventData[0]['etag'],
        ], $authToken, 'DELETE', 'eventdata');

        return !isset($event['error']) ? $event['events'] : NULL;
    }
    
    private function _curlRequest($url, $data=NULL, $authToken=NULL, $type='POST', $jsonName=NULL) {
        $curlOpts = [
            CURLOPT_HEADER => FALSE,
            CURLOPT_RETURNTRANSFER => TRUE,
            CURLOPT_HTTP_VERSION =>  CURL_HTTP_VERSION_2TLS
        ];
        $curlHeaders = [
            'user-agent' => 'YourApp/xxx'
        ];

        if (self::DEBUG) {
            $log = tmpfile();

            $curlOpts += [
                CURLOPT_VERBOSE => TRUE,
                CURLOPT_STDERR => $log
            ];
        }

        if (!is_null($data) && !is_null($jsonName)) {
            $query = http_build_query([
                # This is the only way I could get POST/PUT requests to work with the Zoho API...
                # Wish this was explained in their docs or something, oh well...
                $jsonName => json_encode($data, JSON_UNESCAPED_SLASHES)
            ]);
        }
        else {
            $query = !is_null($data) ? http_build_query($data) : '';
        }
        
        switch($type) {
            case 'GET':
                $curlOpts += [
                    CURLOPT_URL => $url . '?' . $query,
                    CURLOPT_HTTPGET => TRUE
                ];
                break;
            case 'POST':
                $curlOpts += [
                    CURLOPT_URL => $url,
                    CURLOPT_POST => TRUE,
                    CURLOPT_POSTFIELDS => $query
                ];
                break;
            case 'PUT':
            case 'DELETE':
                $curlOpts += [
                    CURLOPT_URL => $url,
                    CURLOPT_CUSTOMREQUEST => $type,
                    CURLOPT_POSTFIELDS => $query
                ];
                break;
        }
        
        if (!is_null($authToken)) {
            $curlHeaders['authorization'] = "Bearer {$authToken}";
        }

        if (count($curlHeaders) > 0) {
            $curlOpts[CURLOPT_HTTPHEADER] = array_map(function($k, $v) {
                return "{$k}: {$v}";
            }, array_keys($curlHeaders), array_values($curlHeaders));
        }

        $request = curl_init();
        curl_setopt_array($request, $curlOpts);
        $response = json_decode(curl_exec($request), TRUE);
        curl_close($request);

        if (self::DEBUG) {
            rewind($log);
            echo '<pre>' . stream_get_contents($log) . '</pre>';
            fclose($log);
        }
        
        return $response;
    }
}
