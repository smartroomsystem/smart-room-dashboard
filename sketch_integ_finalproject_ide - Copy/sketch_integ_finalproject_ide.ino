#include <Adafruit_Sensor.h>
#include <DHT.h>
#include <Wire.h>
#include <LiquidCrystal_I2C.h>

#define DHTPIN 2
#define DHTTYPE DHT11

#define FANPIN 9
#define LEDPIN 8
#define BUZZER 7

DHT dht(DHTPIN, DHTTYPE);
LiquidCrystal_I2C lcd(0x27, 16, 2);

// ----------------------------
// SYSTEM VARIABLES
// ----------------------------
bool isConnected = false;

// Thresholds calibrated for Echague, Isabela climate.
// Normal daily highs: 28–33°C. Fan activates above local summer peak.
const float TEMP_NORMAL_MAX = 28.0;  // Below this = NORMAL
const float TEMP_HIGH_MIN   = 33.0;  // At or above this = HIGH TEMP
                                      // 28–33°C = SAKTO (typical daily range)

unsigned long previousMillis = 0;
const unsigned long interval = 500;

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
  lcd.print("Starting...");
}

// ----------------------------
// MAIN LOOP
// ----------------------------
void loop() {
  // Read commands from Web Serial
  while (Serial.available()) {
    String command = Serial.readStringUntil('\n');
    command.trim();

    if (command == "CMD_START") {
      isConnected = true;
    }
    else if (command == "CMD_STOP") {
      isConnected = false;
    }
  }

  // NOTE: the sensor, fan, LED, buzzer, and LCD now run continuously below,
  // regardless of whether the website is connected. "isConnected" only
  // controls whether readings get sent out over Serial to the web app.

  unsigned long currentMillis = millis();
  if (currentMillis - previousMillis < interval) return;
  previousMillis = currentMillis;

  // ----------------------------
  // READ TEMPERATURE
  // ----------------------------
  float temp = dht.readTemperature();

  if (isnan(temp)) return;

  temp = round(temp * 10.0) / 10.0;

  // ----------------------------
  // TEMPERATURE CONDITIONS
  // Calibrated for Echague, Isabela:
  //   NORMAL    → below 28°C  (cooler than local average low)
  //   SAKTO     → 28°C–32.9°C (normal daily range for this region)
  //   HIGH TEMP → 33°C and above (exceeds typical summer peak)
  // ----------------------------

  // BELOW 28°C = NORMAL
  if (temp < TEMP_NORMAL_MAX) {
    systemStatus = "NORMAL";
    fanState = "OFF";
    digitalWrite(FANPIN, LOW);
    digitalWrite(LEDPIN, LOW);
    noTone(BUZZER);
  }

  // 28°C to below 33°C = SAKTO
  else if (temp >= TEMP_NORMAL_MAX && temp < TEMP_HIGH_MIN) {
    systemStatus = "SAKTO";
    fanState = "OFF";
    digitalWrite(FANPIN, LOW);
    digitalWrite(LEDPIN, LOW);
    noTone(BUZZER);
  }

  // 33°C and above = HIGH TEMP
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
  // Build the whole line as one String first, then print it in one shot.
  // Printing a float directly with lcd.print(temp, 1) sends several
  // back-to-back I2C writes immediately after setCursor(); on PCF8574
  // I2C backpacks this commonly drops the first character or two of the
  // line (e.g. "31.1" showing up as "1.1"). Buffering into a single
  // fixed-width String and writing it as one call avoids that.
  char tempBuf[8];
  dtostrf(temp, 4, 1, tempBuf); // e.g. "31.1" or " 9.5", always 4 chars wide

  String line0 = "Temp:";
  line0 += tempBuf;
  line0 += (char)223;
  line0 += "C   ";

  lcd.setCursor(0, 0);
  delay(2); // let the I2C backpack settle after the cursor move
  lcd.print(line0);

  String line1;
  if (systemStatus == "NORMAL") {
    line1 = "NORMAL         ";
  }
  else if (systemStatus == "SAKTO") {
    line1 = "SAKTO          ";
  }
  else {
    line1 = "HIGH TEMP      ";
  }

  lcd.setCursor(0, 1);
  delay(2);
  lcd.print(line1);

  // ----------------------------
  // SEND DATA TO WEBSITE (only while the web app is connected)
  // ----------------------------
  if (isConnected) {
    Serial.print(temp, 1);
    Serial.print(",");
    Serial.print(systemStatus);
    Serial.print(",");
    Serial.println(fanState);
  }
}