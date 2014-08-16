<?php
namespace Craft;

class GoogleMaps_GoogleMapFieldType extends BaseFieldType
{
    protected $queryParams = false;

    public function getName()
    {
        return Craft::t('Google Map');
    }

    public function defineContentAttribute()
    {
        return array(AttributeType::String, 'column' => ColumnType::Text);
    }

    public function onAfterElementSave()
    {
        $handle = $this->model->handle;
        
        $data = $this->element->$handle;

        if(isset($data->markers))
        {
            foreach($data->markers as $index => $marker)
            {
                if(isset($marker->deleted))
                {
                    if(isset($marker->locationId))
                    {
                        $location = GoogleMaps_LocationRecord::model()->findByPk($marker->locationId);

                        if($location)
                        {
                            $location->delete();
                        }
                    }

                    unset($data->markers[$index]);
                }
                else
                {
                    $marker->isNew = false;
                    
                    $marker->elementId = $this->element->id;

                    $location = GoogleMaps_LocationRecord::model()->findByPk(isset($marker->locationId) ? $marker->locationId : 0);

                    if(!$location)
                    {
                        $location = new GoogleMaps_LocationRecord;
                        $location->elementId = $this->element->id;
                        $location->handle = $handle;
                    }

                    $location->address = $marker->address;
                    $location->addressComponents = $marker->addressComponents;
                    $location->title = $marker->title;
                    $location->content = $marker->content;
                    $location->lat = $marker->lat;
                    $location->lng = $marker->lng;
                    $location->save();

                    $marker->locationId = $location->id;
                }
            }
        }

        if(isset($this->element->$handle))
        {
            $this->element->getContent()->{$handle} = $data->toJson();

            craft()->content->saveContent($this->element);
        }
        
        parent::onAfterElementSave();
    }

    public function prepValue($value)
    {   
        $value = json_decode($value);

        return new GoogleMaps_MapDataModel((array) $value, $this->queryParams);
    }

    public function getSearchKeywords($value)
    {
        $keywords = array();

        if(isset($value->markers))
        {
            foreach($value->markers as $marker)
            {
                $keywords[] = $marker->address;
            }
        }

        return implode(' ', $keywords);
    }

    public function modifyElementsQuery(DbCommand $query, $params = array())
    {
        if(is_null($params))
        {
            return null;
        }

        $defaultParams = array(
            'distanceOperator' => '<='
        );

        $this->queryParams = array_merge($defaultParams, $params);

        $handle = $this->model->handle;

        if(isset($this->queryParams['address']))
        {
            $response = craft()->googleMaps_geocoder->geocode($this->queryParams['address']);
            
            if($response->status != 'OK')
            {
                return null;
            }
    
            $lat = $response->results[0]->geometry->location->lat;
            $lng = $response->results[0]->geometry->location->lng;
        }
        elseif(isset($this->queryParams['lat']) && isset($this->queryParams['lng']))
        {            
            $lat = $this->queryParams['lat'];
            $lng = $this->queryParams['lng'];
        }
        else
        {
            return;
        }

        $this->queryParams['lat'] = $lat;
        $this->queryParams['lng'] = $lng;

        $query->addSelect($handle.'_googlemaps_locations.'.$handle.'_distance');
        $query->join('(SELECT *, ROUND((((ACOS(SIN('.$lat.' * PI() / 180) * SIN('.craft()->db->tablePrefix.'googlemaps_locations.lat * PI() / 180) + COS('.$lat.' * PI() / 180) * COS('.craft()->db->tablePrefix.'googlemaps_locations.lat * PI() / 180) * COS(('.$lng.' - '.craft()->db->tablePrefix.'googlemaps_locations.lng) * PI() / 180)) * 180 / PI()) * 60 * 1.1515) * 1), 1) AS '.$handle.'_distance FROM '.craft()->db->tablePrefix.'googlemaps_locations '.(isset($this->queryParams['distance']) ? 'HAVING '.$handle.'_distance ' . $this->queryParams['distanceOperator'] . ' ' . $this->queryParams['distance'] . ' OR ' . $handle .'_distance IS NULL' : '').' ORDER BY '.$handle.'_distance ASC) '.$handle.'_googlemaps_locations', 'elements.id='.$handle.'_googlemaps_locations.elementId');
       
        $query->order($handle.'_distance asc');
    }

    public function getInputHtml($name, $value)
    { 
        $id = craft()->templates->formatInputId($name);

        // Figure out what that ID is going to look like once it has been namespaced
        $namespacedId = craft()->templates->namespaceInputId($id);

       	// craft()->templates->includeJsFile('//maps.googleapis.com/maps/api/js?sensor=false');
        
        craft()->templates->includeJsResource('googlemaps/js/app.compiled.js');
        craft()->templates->includeCssResource('googlemaps/css/app.css');
        craft()->templates->includeJsFile('//maps.googleapis.com/maps/api/js?key=&sensor=false');

        craft()->templates->includeJs("new GoogleMaps.Fieldtype('#$namespacedId-field .oh-google-maps-wrapper', {
            fieldname: '$name',
            savedData: ".(!empty($value) ? $value->toJson() : "false")."
        })");

        return craft()->templates->render('googlemaps/fieldtype', array(
            
        ));
    }
}