<?php

/*
 * Copyright (C) 2015 Nathan Crause <nathan at crause.name>
 * 
 * The file MetweatherComponent.php is part of Metweather
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 2
 * of the License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 59 Temple Place - Suite 330, Boston, MA  02111-1307, USA.
 */

/**
 * weather component which feeds it's weather from the US meterelogical
 * office
 *
 * @author Nathan Crause
 */
class MetweatherComponent extends TemplaterComponentTmpl {
	
	/**
	 * Contains the base URL with which communication will occur
	 * in order to retrieve weather information
	 */
	const URL_BASE = 'http://graphical.weather.gov/xml/sample_products/browser_interface/ndfdXMLclient.php?';
	
	const OPT_TEMPLATE = 'template';
	
	const OPT_CITY = 'city';
	
	const OPT_LATITUDE = 'latitude';
	
	const OPT_LONGITUDE = 'longitude';
	
	public static $DEFAULTS = [
		self::OPT_TEMPLATE => 'metweather/current-conditions.html',
		self::OPT_CITY => 'Port Angeles',
		self::OPT_LATITUDE => 48.122487,
		self::OPT_LONGITUDE => -123.433434
	];
	
	public function Show($attributes) {
		ClaApplication::Enter('metweather');
		
		$options = array_merge(self::$DEFAULTS, $attributes);
		$cacheKey = 'metaweather|' . sha1(sprintf('%s|%d|%d', 
				$options[self::OPT_CITY], $options[self::OPT_LATITUDE], 
				$options[self::OPT_LONGITUDE]));
				
		// if there's no cache for this location, create one now
		if (!($args = ClaCache::Get($cacheKey))) {
			$args = $this->getLocationWeather($options[self::OPT_LATITUDE], 
				$options[self::OPT_LONGITUDE]);
		
			$args['city.body'] = $options[self::OPT_CITY];
			
			ClaCache::Set($cacheKey, $args, false, 3600);	//cache for one hour
		}
		
		return $this->CallTemplater($options[self::OPT_TEMPLATE], $args);// . '<pre>' . print_r($args, true) . '</pre>';
	}
	
	private function getLocationWeather($latitude, $longitude) {
		$current = $this->getCurrentXML($latitude, $longitude);
		$forecast = $this->getForecasts($latitude, $longitude);
		
		$values = [
			'current_temperature.body' => (string) $current->data->parameters->temperature->value,
			'current_icon.src' => (string) $current->data->parameters->{'conditions-icon'}->{'icon-link'},
			'forecasts.datasrc' => []
		];
			
		for ($i = 1; $i <= 4; ++$i) {
			$values['forecasts.datasrc'][] = [
				'high.body' => (string) $forecast["+$i"]->high,
				'low.body' => (string) $forecast["+$i"]->low,
				'icon.src' => (string) $forecast["+$i"]->icon
			];
		}
		
		return $values;
	}
	
	private function getContent($url) {
		$curl = curl_init($url);

		curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, false);
		curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
		curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);

		if (!($raw = curl_exec($curl))) throw new Exception('Failed to perform HTTP request: ' . curl_error($curl));

		curl_close($curl);
		
		return $raw;
	}
	
	private function getCurrentXML($latitude, $longitude) {
		$url = self::URL_BASE . http_build_query(array(
			'lat' => $latitude,
			'lon' => $longitude,
			'product' => 'time-series',
			'begin' => date('c'),
			'end' => date('c', strtotime('tomorrow')),
			'temp' => 'temp',
			'icons' => 'icons'
		));

		$raw = $this->getContent($url);
		$xml = simplexml_load_string($raw);
		// check if the response is empty
		if (!$xml){
			throw new Exception('Empty response from the National Weather Service. Please try again later.');
		}
		
		return $xml;
	}
	
	public function getForecasts($latitude, $longitude) {
		$url = self::URL_BASE . http_build_query(array(
			'lat' => $latitude,
			'lon' => $longitude,
			'product' => 'time-series',
			'begin' => date('c', strtotime(date('Y-m-d 00:00'))),
			'end' => date('c', strtotime('+4 days')),
			'icons' => 'icons',
			'mint' => 'mint',
			'maxt' => 'maxt'
		));
		
		$raw = $this->getContent($url);
//		return $raw;
		$dom = new DOMDocument();
		
		$dom->loadXML($raw);
		
		$xpath = new DOMXPath($dom);
//		return print_r($dom->getElementsByTagName('temperature'), true);
		
		$lows = $xpath->query('//temperature[@type="minimum"]/value');
		$highs = $xpath->query('//temperature[@type="maximum"]/value');
		$icons = $xpath->query('//conditions-icon/icon-link');
		
		// get the icons time layout
		$timelayout = $xpath->query('//conditions-icon/@time-layout')->item(0)->nodeValue;
		$times = $xpath->query("//time-layout[layout-key='{$timelayout}']/start-valid-time");
		
		// collapse the icons into an array by date
		$iconArray = array();
		for ($i = 0; $i < $icons->length && $i < $times->length; ++$i) {
			$date = date('Y-m-d', strtotime($times->item($i)->nodeValue));
			
			// eject anything still for today
			if ($date == date('Y-m-d')) continue;
			
			if (!key_exists($date, $iconArray)) {
				$iconArray[$date] = array();
			}
			
			$iconArray[$date][] = $icons->item($i)->nodeValue;
		}
		
		// further collapse it into a numeric array
		$iconLinks = array();
		
		foreach ($iconArray as $links) {
			$index = count($links) == 1 ? 1 : ceil(count($links) / 2);
			$iconLinks[] = $links[$index];
		}
		
		$result = array();
		
		for ($i = 0; $i < 4; ++$i) {
			$result['+' . ($i + 1)] = (object) array(
				'high' => $highs->item($i)->nodeValue,
				'low' => $lows->item($i)->nodeValue,
				'icon' => $iconLinks[$i]
			);
		}
		
		return $result;
	}
	
}
