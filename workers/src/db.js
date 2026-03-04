const path = require("path");
const dotenv = require("dotenv");
const mysql = require("mysql2/promise");

dotenv.config({ path: path.resolve(__dirname, "../../.env") });

function dbConfigFromEnv() {
  return {
    host: process.env.DB_HOST || "localhost",
    user: process.env.DB_USER || "root",
    password: process.env.DB_PASS || "",
    database: process.env.DB_NAME || "lemelani_loans",
    waitForConnections: true,
    connectionLimit: Number(process.env.WORKER_DB_POOL_SIZE || 5),
    queueLimit: 0
  };
}

function createPool() {
  return mysql.createPool(dbConfigFromEnv());
}

module.exports = {
  createPool
};

