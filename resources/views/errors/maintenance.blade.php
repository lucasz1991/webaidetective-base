<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Wir sind bald wieder da!</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-color: #ff7f7f;
            --secondary-color: #2c3e50;
            --background-color: #e9ecef;
            --text-color: #444;
            --shadow: rgba(0, 0, 0, 0.1);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Poppins', sans-serif;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            height: 100vh;
            text-align: center;
            background: var(--background-color);
            color: var(--text-color);
            padding: 20px;
        }

        .container {
            max-width: 600px;
            background: white;
            padding: 40px;
            border-radius: 10px;
            box-shadow: 0px 4px 10px var(--shadow);
            animation: fadeIn 1.2s ease-in-out;
        }

        h1 {
            font-size: 2.5rem;
            color: var(--primary-color);
            font-weight: 600;
            margin-bottom: 10px;
        }

        p {
            font-size: 1.2rem;
            margin-bottom: 20px;
            color: var(--secondary-color);
        }

        .loader {
            width: 50px;
            height: 50px;
            border: 5px solid transparent;
            border-top-color: var(--primary-color);
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin: 20px auto;
        }

        .countdown {
            font-size: 1.5rem;
            font-weight: bold;
            color: var(--primary-color);
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        @media (max-width: 600px) {
            h1 { font-size: 2rem; }
            p { font-size: 1rem; }
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🚧 Wir sind bald wieder da!</h1>
        <p>Unsere Website wird gerade aktualisiert. Bitte habe etwas Geduld.</p>
        <div class="loader"></div>
        <p class="countdown">
            Aktualisierung in <span id="countdown" data-timestamp="{{ isset($lastUpdated) ? \Carbon\Carbon::parse($lastUpdated)->setTimezone('UTC')->timestamp : 0 }}">kürze!</span>
        </p>
    </div>

    <script>
        document.addEventListener("DOMContentLoaded", function () {
            let countdownElement = document.getElementById("countdown");
            let lastUpdated = parseInt(countdownElement.getAttribute("data-timestamp")); // Holt UTC-Timestamp
            let currentTimeUTC = Math.floor(Date.now() / 1000); // Aktuelle Zeit in UTC-Sekunden

            // **Ermittle die Benutzerzeitzone (CET = UTC+1, CEST = UTC+2)**
            let userTimeZoneOffset = new Date().getTimezoneOffset() / -60; // Benutzerzeitzone in Stunden
            let germanyOffset = userTimeZoneOffset === 2 ? 2 : 1; // Falls Sommerzeit (CEST), dann UTC+2

            console.log("Last Updated (UTC):", new Date(lastUpdated * 1000).toISOString());
            console.log("Current Time (UTC):", new Date(currentTimeUTC * 1000).toISOString());
            console.log("User Zeitzone:", userTimeZoneOffset);
            console.log("Deutschland Offset:", germanyOffset);

            // **Korrigiere NUR lastUpdated um die Differenz zur lokalen Zeitzone**
            let adjustedLastUpdated = lastUpdated + (germanyOffset * 3600);

            let secondsRemaining = Math.max(600 - (currentTimeUTC - adjustedLastUpdated), 0); // 10 Minuten - vergangene Zeit

            function updateCountdown() {
                if (secondsRemaining <= 0) {
                    clearInterval(countdownTimer);
                    countdownElement.innerText = "gleich!";
                    return;
                }

                if (secondsRemaining > 60) {
                    let minutes = Math.floor(secondsRemaining / 60);
                    let seconds = secondsRemaining % 60;
                    countdownElement.innerText = `${minutes} Min. ${seconds} Sek.`;
                } else {
                    countdownElement.innerText = `${secondsRemaining} Sek.`;
                }

                secondsRemaining--;
            }

            updateCountdown(); // Sofort aufrufen, um Verzögerung zu vermeiden
            let countdownTimer = setInterval(updateCountdown, 1000);
        });
    </script>
</body>
</html>
