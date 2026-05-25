# MakeX Scoring App API

API for the Make X scoring/referee app.

## Prerequisites

- **XAMPP:** Make sure you have [XAMPP](https://www.apachefriends.org/index.html) downloaded and installed on your system.

## Installation

1. **Download the Project**

   Download the `MakeX_ScoringApp_Api` as a ZIP file from GitHub, or clone the repository if you prefer.

2. **Extract into XAMPP Directory**

   Extract the contents of the ZIP file into a new folder within your `xampp/htdocs` directory (e.g., `C:\xampp\htdocs\MakeX_ScoringApp_Api`).

3. **Configure phpMyAdmin Access (Optional, for Local Access Permission)**

   If you are having issues accessing phpMyAdmin due to permissions, you can modify your `httpd-xampp.conf` file:

   - Open XAMPP.
   - Next to Apache, click the `Config` button and select `Apache (httpd-xampp.conf)`.
   - Search for the following line in the config file:
     ```apache
     Alias /phpmyadmin "C:/xampp/phpMyAdmin/"
     ```
   - Just below it, you'll find lines regarding `<Directory "C:/xampp/phpMyAdmin">`. Change the `Require local` line to:
     ```
     Require all granted
     ```
   - Save the file and restart Apache.

   **Note:** Adjust this only in a secure/local environment.

4. **Start Apache and MySQL**

   Use the XAMPP Control Panel to start both `Apache` and `MySQL`.

5. **Import the Database**

   - Open [http://localhost/phpmyadmin](http://localhost/phpmyadmin) in your browser.
   - Create a new database (e.g., `makex_scoringapp`).
   - Import the provided database `.sql` file (if available) located in this repository.

6. **Configure Database Connection**

   Edit the database configuration file (e.g., `config.php` or `.env`) as needed with your database credentials (username: `root`, password is usually empty for XAMPP by default).

## Usage

- Access your API at [http://localhost/MakeX_ScoringApp_Api](http://localhost/MakeX_ScoringApp_Api)
- Use tools like Postman or your frontend application to interact with the API endpoints.

## License

This project is for educational and competition use.

---

Feel free to adjust the instructions as needed for your specific setup.
