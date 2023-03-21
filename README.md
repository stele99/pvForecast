# Photovoltaik Forecast Module for IP-Symcon
PV Forecast with weather Data from DWD


## Setup
Erstellen einer instanz pvForecast.
Objektparameter pflegen.

## Wetter und Position
Geben Sie den Längen und Breitengrad der Module an
Wichtig ist noch eine Wetterstation des DWD in der Nähe zu wählen, die auch die Globalstrahlung ausgibt. Dies ist leider nicht bei allen Stationen der Fall.

### Wichtige Parameter erklärt
1. Azimuth: Abweichung in Grad von idealser Südausrichtung (=0)
2. Neigung: zb. auf 30°Dach - Neigung = 30
3. Effizienz: Hier muss die Effizenz des PV Modules angegeben werden. Diese liegt in der Regel zwischen 70 und 75%
4. Temperaturkoeffizient: kann von dem Moduldatenblatt abgelesen werden, ansonsten belassen.
5. Horizont: 0 grad bei Flachland, Entsprechen der Winkel zur Bergspitze.

Verschattungsobjekt
1. Himmelsrichtung: In welcher Himmelsrichtung befindet sich das objekt (Norden = 0 Grad)



Forecast wird stündlich automatisch neu berechnet. (Für den aktuellen Tag bis 10 Uhr, dann wird der Forecast des aktuellen Tages nicht mehr neu berechnet).

## Befehle
Folgende Befehle können in Scripten verwendet werden:

$instance = ID Des PV Forecast Objektes


**pvFC_Update (integer $instance, boolean $force)**

$force = true|false :fordert zwingende Aktualisierung an ohne cache
  
  
**pvFC_getForecast(integer $instance): array()**

Liefert die Forecast Daten als Array zurück.


**pvFC_getHourForecast(integer $instance, integer $timestamp): integer**

Liefert die vorhergesagten Watt der angegeben Stunde (Timestamp) zurück.


**pvFC_getDayForecast(integer $instance, integer $timestamp): integer**

Liefert die vorhergesagten Watt für den angefragten Tag (Timestamp) zurück.


___
(c) 2022 stele99
