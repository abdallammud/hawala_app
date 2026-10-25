<?php

/**
 * Starting list of cities per country (names match config('hawala.countries')).
 * Admins add more under Setup → Cities; this only saves typing on day one.
 */
return [
    'Somalia' => [
        'Mogadishu', 'Hargeisa', 'Kismayo', 'Baidoa', 'Garowe', 'Bosaso', 'Galkayo', 'Beledweyne', 'Berbera',
        'Burao', 'Borama', 'Merca', 'Jowhar', 'Afgooye', 'Las Anod', 'Erigavo', 'Dhusamareb', 'Qardho', 'Luuq',
        'Bardhere', 'Jilib', 'Wajid', 'Hudur', 'Baraawe', 'Buulo Burte', 'Adale', 'Eyl', 'Hobyo', 'Garbahaarey',
    ],
    'Ethiopia' => ['Addis Ababa', 'Dire Dawa', 'Jijiga', 'Gode', 'Kebri Dahar', 'Dollo', 'Harar', 'Hawassa', 'Adama', 'Mekelle'],
    'Kenya' => ['Nairobi', 'Mombasa', 'Garissa', 'Mandera', 'Wajir', 'Kisumu', 'Nakuru', 'Eldoret', 'Dadaab', 'Thika'],
    'Djibouti' => ['Djibouti', 'Ali Sabieh', 'Dikhil', 'Tadjoura', 'Obock', 'Arta'],
    'Eritrea' => ['Asmara', 'Massawa', 'Keren', 'Assab', 'Mendefera'],
    'Uganda' => ['Kampala', 'Entebbe', 'Jinja', 'Gulu', 'Mbarara'],
    'Tanzania' => ['Dar es Salaam', 'Dodoma', 'Arusha', 'Mwanza', 'Zanzibar'],
    'Egypt' => ['Cairo', 'Alexandria', 'Giza', 'Port Said', 'Suez'],
    'Yemen' => ['Sanaa', 'Aden', 'Taiz', 'Mukalla', 'Hodeidah'],
    'South Africa' => ['Johannesburg', 'Cape Town', 'Durban', 'Pretoria', 'Port Elizabeth'],

    'Sweden' => ['Stockholm', 'Gothenburg', 'Malmö', 'Uppsala', 'Västerås', 'Örebro', 'Linköping', 'Helsingborg', 'Jönköping', 'Norrköping', 'Lund', 'Umeå', 'Borås', 'Eskilstuna', 'Södertälje', 'Gävle', 'Sundsvall', 'Växjö', 'Karlstad', 'Halmstad'],
    'Norway' => ['Oslo', 'Bergen', 'Trondheim', 'Stavanger', 'Drammen', 'Fredrikstad', 'Kristiansand', 'Sandnes', 'Tromsø', 'Sarpsborg', 'Skien', 'Ålesund', 'Moss', 'Haugesund', 'Bodø'],
    'Denmark' => ['Copenhagen', 'Aarhus', 'Odense', 'Aalborg', 'Esbjerg', 'Randers', 'Kolding', 'Horsens', 'Vejle', 'Roskilde', 'Herning', 'Silkeborg', 'Næstved', 'Fredericia', 'Viborg'],
    'Finland' => ['Helsinki', 'Espoo', 'Tampere', 'Vantaa', 'Oulu', 'Turku', 'Jyväskylä', 'Lahti', 'Kuopio', 'Pori'],
    'Netherlands' => ['Amsterdam', 'Rotterdam', 'The Hague', 'Utrecht', 'Eindhoven', 'Groningen', 'Tilburg', 'Almere', 'Breda', 'Nijmegen', 'Arnhem', 'Leiden'],
    'Belgium' => ['Brussels', 'Antwerp', 'Ghent', 'Charleroi', 'Liège', 'Bruges', 'Leuven', 'Mechelen'],
    'Germany' => ['Berlin', 'Hamburg', 'Munich', 'Cologne', 'Frankfurt', 'Stuttgart', 'Düsseldorf', 'Dortmund', 'Essen', 'Leipzig', 'Bremen', 'Dresden', 'Hanover', 'Nuremberg', 'Duisburg', 'Bonn'],
    'France' => ['Paris', 'Marseille', 'Lyon', 'Toulouse', 'Nice', 'Nantes', 'Strasbourg', 'Montpellier', 'Bordeaux', 'Lille', 'Rennes'],
    'United Kingdom' => ['London', 'Birmingham', 'Manchester', 'Leeds', 'Liverpool', 'Sheffield', 'Bristol', 'Cardiff', 'Leicester', 'Nottingham', 'Newcastle', 'Glasgow', 'Edinburgh', 'Coventry', 'Bradford', 'Derby', 'Oldham', 'Southampton', 'Belfast'],
    'Ireland' => ['Dublin', 'Cork', 'Limerick', 'Galway', 'Waterford'],
    'Italy' => ['Rome', 'Milan', 'Naples', 'Turin', 'Bologna', 'Florence', 'Genoa', 'Palermo', 'Verona', 'Brescia'],
    'Spain' => ['Madrid', 'Barcelona', 'Valencia', 'Seville', 'Zaragoza', 'Málaga', 'Bilbao'],
    'Switzerland' => ['Zurich', 'Geneva', 'Basel', 'Bern', 'Lausanne', 'Lucerne', 'St. Gallen'],
    'Austria' => ['Vienna', 'Graz', 'Linz', 'Salzburg', 'Innsbruck'],
    'Poland' => ['Warsaw', 'Kraków', 'Łódź', 'Wrocław', 'Poznań', 'Gdańsk'],
    'Greece' => ['Athens', 'Thessaloniki', 'Patras', 'Heraklion'],
    'Malta' => ['Valletta', 'Birkirkara', 'Sliema', 'St. Paul\'s Bay'],

    'Canada' => ['Toronto', 'Ottawa', 'Montreal', 'Vancouver', 'Calgary', 'Edmonton', 'Winnipeg', 'Hamilton', 'London', 'Kitchener', 'Mississauga', 'Halifax', 'Regina', 'Saskatoon', 'Windsor'],
    'United States' => ['Minneapolis', 'Columbus', 'Seattle', 'Washington', 'New York', 'Atlanta', 'Boston', 'Chicago', 'Dallas', 'Denver', 'Houston', 'Los Angeles', 'Nashville', 'Portland', 'San Diego', 'Phoenix', 'Salt Lake City', 'Kansas City', 'St. Cloud', 'Lewiston', 'Clarkston', 'Cedar Rapids', 'Des Moines', 'Philadelphia', 'San Jose', 'Las Vegas', 'Miami'],
    'Australia' => ['Sydney', 'Melbourne', 'Brisbane', 'Perth', 'Adelaide', 'Canberra'],
    'New Zealand' => ['Auckland', 'Wellington', 'Christchurch', 'Hamilton'],

    'United Arab Emirates' => ['Dubai', 'Abu Dhabi', 'Sharjah', 'Ajman', 'Al Ain'],
    'Saudi Arabia' => ['Riyadh', 'Jeddah', 'Mecca', 'Medina', 'Dammam'],
    'Qatar' => ['Doha', 'Al Rayyan', 'Al Wakrah'],
    'Kuwait' => ['Kuwait City', 'Hawalli', 'Salmiya'],
    'Turkey' => ['Istanbul', 'Ankara', 'Izmir', 'Bursa', 'Antalya'],
];
