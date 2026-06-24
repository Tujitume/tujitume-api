let mapOptions = {
    center:[51.958, 9.141],
    zoom:10
}

let map = new L.map('map' , mapOptions);
let layer = new L.TileLayer('http://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png');
map.addLayer(layer);
let marker = new L.Marker([51.958, 9.141]);
marker.addTo(map);

let marker2 = new L.Marker([51.60526413029149, -0.06537114999999996]);
let marker3 = new L.Marker([-4.000000000000000, 40.000000000000000]);
marker2.addTo(map);
marker3.addTo(map);