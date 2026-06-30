#include <Adafruit_Sensor.h>

#include <DHT.h>
#include <Wire.h>

#include <LiquidCrystal_I2C.h>

#define DHTPIN 2
#define DHTTYPE DHT22

#define FANPIN 9
#define LEDPIN 8
#define BUZZER 7

DHT dht(DHTPIN, DHTTYPE);
LiquidCrystal_I2C lcd(0x27, 16, 2);

// ----------------------------
// SYSTEM VARIABLES
// ----------------------------
bool isConnected = false;

// Echague, Isabela climate reference:
// Normal lows: 20°C (Jan) to 24°C (warm months)
// Normal highs: 28°C (cool months) to 33°C (summer)
const float TEMP_NORMAL_MAX = 28.0;   // below this = cooler than normal
const float TEMP_HIGH_THRESHOLD = 32.0; // above this = exceeds summer peak

unsigned long previousMillis = 0;
const unsigned long interval = 500;

// Global status tracking variables to persist states across loops
String systemStatus = "NORMAL";
String fanState = "OFF";

// ----------------------------
// SETUP
// ----------------------------
void setup() {

  Serial.begin(9600);

  pinMode(FANPIN, OUTPUT);
  pinMode(LEDPIN, OUTPUT);
  pinMode(BUZZER, OUTPUT);

  digitalWrite(FANPIN, LOW);
  digitalWrite(LEDPIN, LOW);
  noTone(BUZZER);

  dht.begin();

  lcd.init();
  lcd.backlight();

  lcd.setCursor(0, 0);
  lcd.print("Waiting Web...");
}

// ----------------------------
// MAIN LOOP
// ----------------------------
void loop() {

  // Read commands from Web Serial
  while (Serial.available()) {

    String command = Serial.readStringUntil('\n');
    command.trim();

    // Accept both old and new commands
    if (command == "START" || command == "CMD_START") {

      if (!isConnected) {

        isConnected = true;
        lcd.clear();
        lcd.setCursor(0, 0);
        lcd.print("WEB CONNECTED");

        previousMillis = millis();
      }
    }

    if (command == "STOP" || command == "CMD_STOP") {

      disconnectSystem();
    }
  }

  // If browser is disconnected,
  // make sure every output is OFF.
  if (!isConnected) {

    digitalWrite(FANPIN, LOW);
    digitalWrite(LEDPIN, LOW);
    noTone(BUZZER);

    // Reset tracking variables
    systemStatus = "NORMAL";
    fanState = "OFF";

    return;
  }

  unsigned long currentMillis = millis();
  if (currentMillis - previousMillis < interval) {
    return;
  }

  previousMillis = currentMillis;

  // ----------------------------
  // READ TEMPERATURE
  // ----------------------------

  float temp = dht.readTemperature();

  // Ignore bad readings instead of
  // clearing the LCD every time.
  if (isnan(temp)) {
    return;
  }

  temp = round(temp * 10.0) / 10.0;

  // ----------------------------
// TEMPERATURE CONDITIONS
// ----------------------------

// BELOW 28°C = NORMAL
// (cooler than Echague's typical daytime low in cool months)
if (temp < TEMP_NORMAL_MAX) {

  systemStatus = "NORMAL";
  fanState = "OFF";

  digitalWrite(FANPIN, LOW);
  digitalWrite(LEDPIN, LOW);
  noTone(BUZZER);
}

// 28°C to 32°C = SAKTO
// (within Echague's normal high range of 28°C–33°C)
else if (temp >= TEMP_NORMAL_MAX && temp < TEMP_HIGH_THRESHOLD) {

  systemStatus = "SAKTO";
  fanState = "OFF";

  digitalWrite(FANPIN, LOW);
  digitalWrite(LEDPIN, LOW);
  noTone(BUZZER);
}

// Above 32°C = HIGH TEMP
// (exceeds Echague's summer peak of ~33°C — abnormally hot)
else {

  systemStatus = "HIGH TEMP";
  fanState = "ON";

  digitalWrite(FANPIN, HIGH);
  digitalWrite(LEDPIN, HIGH);
  tone(BUZZER, 1000);
}

  // ----------------------------
  // LCD DISPLAY
  // ----------------------------

  lcd.setCursor(0, 0);
  lcd.print("Temp:");
  lcd.print(temp, 1);
  lcd.print((char)223);
  lcd.print("C   ");      // clears remaining characters

  lcd.setCursor(0, 1);

if (systemStatus == "NORMAL") {
  lcd.print("NORMAL         ");
}
else if (systemStatus == "SAKTO") {
  lcd.print("SAKTO          ");
}
else {
  lcd.print("HIGH TEMP      ");
}

  // ----------------------------
  // SEND DATA TO WEBSITE
  // ----------------------------

  Serial.print(temp, 1);
  Serial.print(",");
  Serial.print(systemStatus);
  Serial.print(",");
  Serial.println(fanState);
} // END OF LOOP


// ==============================
// DISCONNECT SYSTEM
// ==============================

void disconnectSystem() {

  isConnected = false;

  digitalWrite(FANPIN, LOW);
  digitalWrite(LEDPIN, LOW);

  noTone(BUZZER);
  
  systemStatus = "NORMAL";
  fanState = "OFF";
  
  lcd.clear();
  lcd.setCursor(0, 0);
  lcd.print("Waiting Web...");
}