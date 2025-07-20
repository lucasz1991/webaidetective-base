<script>
    function chatbot() {
        return {
        message: '',
        chatHistory: JSON.parse(localStorage.getItem('chatbot_history')) || [],
        showChat: false,
        isLoading: false, // Zustand für Ladeanimation
        async sendMessage() {
            if (this.message.trim() === '') return;

            let userMessage = this.message;
            this.chatHistory.push({ role: 'user', content: userMessage });

            this.isLoading = true;
            this.$nextTick(() => {
                this.$refs.messages.scrollTop = this.$refs.messages.scrollHeight;
            });

            this.message = '';
            this.saveChatHistory();

            let attempt = 0; // Versuchs-Zähler

            while (attempt < 5) { // Genau 5 Versuche
                try {
                    let response = await fetch("https://openrouter.ai/api/v1/chat/completions", {
                        method: "POST",
                        headers: {
                            "Authorization": "Bearer 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",
                            "HTTP-Referer": "http://localhost",
                            "X-Title": "MiniFinds",
                            "Content-Type": "application/json"
                        },
                        body: JSON.stringify({
                            "model": "qwen/qwq-32b:free",
                            "messages": [
                                { 
                                    "role": "system", 
                                    "content": "Du bist der MiniFinds Assistent. MiniFinds ist ein Dauerflohmarkt in Hamburg, bei dem Kunden Verkaufsregale mieten, um Secondhand-Produkte wie Kinderkleidung, Spielzeug und Zubehör zu verkaufen. Einkäufe erfolgen ausschließlich im Laden. Verkäufe werden den Kunden in Echtzeit mitgeteilt. Produkte sind online durchsuchbar und können auf eine persönliche Wunschliste gesetzt oder geteilt werden.\n\n**Standort & Öffnungszeiten:**\n📍 Kanalstraße 14, 22085 Hamburg\n🕒 Mo-Fr: 09:00 - 18:00 Uhr, Sa: 09:00 - 16:00 Uhr, So: Geschlossen\n\n**Regeln für deine Antworten:**\n1️⃣ **Geschäftsmodell:** MiniFinds ist ein Dauerflohmarkt. Kunden mieten Regale für ihre Produkte, die von Besuchern vor Ort gekauft werden.\n2️⃣ **Regalbuchung:** Mietdauer 7, 14 oder 21 Tage (26€, 46€, 66€). Kunden erfassen online ihre Produkte und bringen die Artikel zum start der Miete vorbei, dann wird das Regal mit den Produkten gefüllt. Verkäufe werden automatisch erfasst.\n3️⃣ **Provision:** 16 % des Verkaufspreises.\n4️⃣ **Verkauf & Auszahlung:** Verkäufe erfolgen nur im Laden. Einnahmen können nach Mietende über das Kundenkonto angefordert werden.\n5️⃣ **Etiketten:** Etiketten mit Barcodes werden vor Ort im Laden ausgedruckt und an die Kunden ausgegeben. Diese bringen die etikettierten Produkte ins Regal.\n6️⃣ **Keine Online-Bestellungen:** Nur Vor-Ort-Käufe möglich.\n7️⃣ **Kundenfreundliche Antworten:** Erkläre MiniFinds einfach und verständlich, besonders für neue Nutzer.\n8️⃣ **Regalverlängerung:** Mietdauer kann erweitert werden, zu den gewohnten Mietdauern und Konditionen.\n9️⃣ **Produktanfragen:** Kunden können Fragen zu Produkten stellen, die sie im Laden gesehen haben.\n🔟 **Keine Fachbegriffe:** Antworte klar, präzise und freundlich.\n\n⚠️ **Antworten bitte kurz (max. 4 Sätze)!** Bitte auf deutsch antowrten und keine chinesischen Zeichen, oder Emojis verwenden zum antworten bitte. Keine Links in den Antworten. Danke für deine Hilfe!"
                                },
                                ...this.chatHistory
                            ]
                        })
                    });

                    let data = await response.json();
                    let botMessage = data?.choices?.[0]?.message?.content;

                    if(botMessage !== '') {
                        this.chatHistory.push({ role: 'assistant', content: botMessage });
                        this.saveChatHistory();
                        this.isLoading = false;
                        this.$nextTick(() => {
                            this.$refs.messages.scrollTop = this.$refs.messages.scrollHeight;
                        });
                        return; 
                    }

                } catch (error) {
                }

                attempt++; // Versuchszähler erhöhen
            }

            // Nach 5 fehlgeschlagenen Versuchen:
            this.chatHistory.push({ role: 'assistant', content: "Ich habe dazu leider keine Antwort." });
            this.saveChatHistory();
            this.isLoading = false;
            this.$nextTick(() => {
                this.$refs.messages.scrollTop = this.$refs.messages.scrollHeight;
            });
        },
        clearChat() {
            this.chatHistory = [];
            localStorage.removeItem('chatbot_history');
        },
        saveChatHistory() {
            localStorage.setItem('chatbot_history', JSON.stringify(this.chatHistory));
        }
    };
}
</script>