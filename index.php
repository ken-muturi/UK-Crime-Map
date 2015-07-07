<?php
/*
Plugin Name: UK Crime Map
Plugin URI: http://policeapps.co.uk
Description: Attaches various Crimes to geographic coordinates.
Version: 1.0.0
Author: Kenneth Muturi
Author URI: http://www.ygroup.us
Minimum WordPress Version Required: 3.0
*/

//shortcode
add_shortcode('crimemap', function($atts) {
	$atts = shortcode_atts(
		array(
			'type' => 'neighbourhoods',
			'query' => null,
			'width' => '600',
			'height' => '300',
			'zoom' => 2,
			'maptype' => 'ROADMAP',
			'scale' => 2
		), $atts
	);
	
	$crime_api_parameters = Ukcrimemap::prepare_search($atts['query']);

	$api_data = get_transient("api_data_{$url}");
	if(! $api_data) 
	{
		$api_data = Ukcrimemap::fetch_api_data($crime_api_parameters);
	}

	$data = array();
	foreach ($api_data->contents as $content) 
	{
		$marker = strtoupper($content['type']).".png";
		$data [] = array(
			'latitude' =>  $content['latitude'],
			'longitude' => $content['longitude'],
			'title' => $content['title'],
			'description' => $content['description'],
			'type' => $content['type'],
			'marker' => plugins_url( "icons/{$marker}", __FILE__ )
			
		);
	}
	return Ukcrimemap::map($atts, $data);
});


/**
 * Class Ukcrimemap
 * some help class for our crime maps app
 */
class Ukcrimemap
{
	//prepare search parameters
	public static function prepare_search($search = null)
	{
		if($search)
		{
			//zip code
			if(preg_match("/^[A-Z]{1,2}[0-9]{2,3}[A-Z]{2}$/", $search) || preg_match("/^[A-Z]{1,2}[0-9]{1}[A-Z]{1}[0-9]{1}[A-Z]{2}$/",$postcode) || preg_match("/^GIR0[A-Z]{2}$/", $search))
			{
				return array(
					'type' => '',
				);	
			}

			//geo coordinates
			//if(preg_match('/([0-9.-]+).+?([0-9.-]+)/', $search, $matches))
			if(preg_match_all("/(?<lat>[-+]?([0-9]+\.[0-9]+)).*(?<long>[-+]?([0-9]+\.[0-9]+))/", $search, $matches))
			{
				return array(
					'type' => '/crimes-street/all-crime',
					'latitude' => (float)$matches['lat'],
					'longitude' => (float)$matches['long']
				);	
			}

			//town/area
			if(preg_match('//', $search, $matches))
			{
				// return array(
				// 	'type' => '/crimes-street/all-crime',
				// 	'latitude' => (float)$matches['lat'],
				// 	'longitude' => (float)$matches['long']
				// );	
			}	

			//date
			if ($date = DateTime::createFromFormat('Y-m-d', $search) || $date = DateTime::createFromFormat('Y-m-d', $search)) 
			{
			   	return array(
					'type' => '/crimes-no-location?category=all-crime&force=warwickshire',
					'month' => $date->format('Y-m')
				);	
			}

			//type
			if(preg_match('//', $search, $matches))
			{

			}				
		}
		return false;
	}

	//get data from the api
	public static function fetch_api_data($options = null)
	{
		$month = isset($options[$month]) ? $options[$month] : date('Y-m', time());	
		switch ($options['type']) 
		{
			case 'crimes-at-location':
				$location_id = $options['location_id'];
				$url = "crimes-at-location?date={$month}&location_id={$location_id}";
				break;			

			case 'lat-long':
				$url = "crimes-street/all-crime?lat={$latitude}&lng={$longitude}&date={$month}";
				break;		

			case 'poly':
				$points = array();
				foreach ($poly_points as $lat => $lon) 
				{
					$points[] = "{$lat},{$lon}"; 
				}
				$points = join(':', $points);
				$url = "crimes-street/all-crime?poly={$points}&date={$month}";
				break;
			
			case 'neighbourhoods':
			default:
				$url = "{$town}/neighbourhoods";
				break;
		}

		$data = new stdClass();
		$data->api = $url;
		$data->crimes = self::curl("http://data.police.uk/api/{$url}");

		set_transient("api_data_{$url}", $content, 60 * 15);
		return $content;
	}

	//mapping
	public static function map($atts =array(), $locations = array())
	{
		extract($atts);
		return '
	        <script src="http://maps.google.com/maps/api/js?sensor=false"></script>  
	         <script type="text/javascript" src="'.plugins_url( 'js/markerclusterer.js', __FILE__ ).'"></script>
	        <script type="text/javascript"> 
	        // Info window trigger function 
	         function onItemClick(pin, map) {  
	           // Create content  
	           var contentString = "<p><h6>" + pin.data.title + "</h6> <small>" + pin.data.description + "</small></p>";
	            
	           // Replace our Info Window content and position 
	           infowindow.setContent(contentString); 
	           infowindow.setPosition(pin.position); 
	           infowindow.open(map) 
	         } 

	         function theme_map_initialize() {
	           var center = new google.maps.LatLng(0.1757807, 23.7304700);
	           var options = {
	                zoom: '. $zoom .',
	                center: center,
	                scale: '. $scale .',
	                mapTypeId: google.maps.MapTypeId.'.$maptype.',
	           };
	           var data = '.json_encode($locations).';
	           var map = new google.maps.Map(document.getElementById("map"), options);

	           var markers = [];
	           $.each(data, function(i, res) {
	               var latLng = new google.maps.LatLng(res.latitude, res.longitude);
	               var marker = new google.maps.Marker({ position: latLng, map: map, data: res});
	               
	               google.maps.event.addListener(marker, "click", function() { 
	                   map.setCenter(new google.maps.LatLng(marker.position.lat(), marker.position.lng())); 
	                   map.setZoom(5); 
	                   onItemClick(marker, map); 
	               }); 
	               
	               markers.push(marker);
	           });

	           var markerCluster = new MarkerClusterer(map, markers);
	           infowindow = new google.maps.InfoWindow(); 
	         }
	        google.maps.event.addDomListener(window, "load", theme_map_initialize);
	        </script>
		';
	}

	//curl
	public static function curl($url)
	{
		$c = curl_init($url);
		curl_setopt($c, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($c, CURLOPT_TIMEOUT, 10);
		return json_decode(curl_exec($c));
	}
		
	// we could use the wordpress way	
	public static function remote_request($url)
	{
		$response = wp_remote_request( 
			'http://example.com?some=parameter', 
			array('ssl_verify' => true)
		);

		is_wp_error( $response ) AND printf(
			'There was an ERROR in your request.<br />Code: %s<br />Message: %s', 
			$response->get_error_code(), 
			$response->get_error_message()
		);

		$response_code   = wp_remote_retrieve_response_code( $response );
		$response_status = wp_remote_retrieve_response_message( $response );
		
		// Prepare the data:
		$content = trim( wp_remote_retrieve_body( $response ) );
		// Convert output to JSON
		if (
		    strstr(
		         wp_remote_retrieve_header( $response, 'content-type' )
		        ,'json'
		    ) )
		{
		    $content = json_decode( $content );
		}
		// … else, after a double check, we simply go with XML string
		elseif (
		    strstr(
		         wp_remote_retrieve_header( $response, 'content-type' )
		        ,'application/xhtml+xml'
		    ) )
		{
		    // Lets make sure it is really an XML file
		    // We also get cases where it's "<?XML" and "<?xml"
		    if ( '<?xml' !== strtolower( substr( $content, 0, 5 ) ) )
		        return false;

		    // Also return stuff wrapped up in <![CDATA[Foo]]>
		    $content = simplexml_load_string( $content, null, LIBXML_NOCDATA );
		}	
	}
}