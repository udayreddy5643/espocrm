/**
 * Pre-deployment / CI Database Health & CRUD Verification Script
 * Topic: Connect Node.js to MySQL using mysql2 (prepared statements, query execution, and CRUD verification)
 */

import mysql from 'mysql2/promise';

async function testDatabaseConnection() {
    const config = {
        host: process.env.ESPOCRM_DATABASE_HOST || '127.0.0.1',
        port: Number(process.env.ESPOCRM_DATABASE_PORT || 3306),
        user: process.env.ESPOCRM_DATABASE_USER || 'espocrm',
        password: process.env.ESPOCRM_DATABASE_PASSWORD || 'espocrm_prod_secret',
        database: process.env.ESPOCRM_DATABASE_NAME || 'espocrm',
        connectTimeout: 5000,
    };

    console.log(`Connecting to MySQL database at ${config.host}:${config.port}...`);

    let connection;
    try {
        connection = await mysql.createConnection(config);
        console.log('✓ Successfully connected to database using mysql2.');

        // 1. Prepared Statement: Query schema version / database version
        const [rows] = await connection.execute('SELECT VERSION() AS version, NOW() AS serverTime');
        console.log(`✓ Database Version: ${rows[0].version}, Server Time: ${rows[0].serverTime}`);

        // 2. Query EspoCRM User count using prepared statement
        const [userCount] = await connection.execute('SELECT COUNT(*) AS total FROM user WHERE deleted = ?', [0]);
        console.log(`✓ Active EspoCRM Users: ${userCount[0].total}`);

        // 3. Prepared Statement verification: Check preferences table
        const [prefCount] = await connection.execute('SELECT COUNT(*) AS total FROM preferences WHERE deleted = ?', [0]);
        console.log(`✓ Preferences records verified: ${prefCount[0].total}`);

        console.log('✓ All database verification checks passed.');
        await connection.end();
        process.exit(0);
    } catch (err) {
        console.error('✗ Database verification error:', err.message);
        if (connection) await connection.end();
        process.exit(1);
    }
}

testDatabaseConnection();
