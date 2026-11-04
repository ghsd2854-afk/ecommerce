'use strict';

const { pool } = require('./db');

class ValidationError extends Error {}

function validateReservationId(reservationId) {
  if (typeof reservationId !== 'string' || reservationId.trim() === '' || reservationId.length > 64) {
    throw new ValidationError('reservation_id is required (max 64 characters).');
  }
}

/**
 * Merge duplicated products and sort by product_id.
 * Sorting means every transaction locks stock rows in the same order,
 * which prevents deadlocks between concurrent reservations.
 */
function normalizeItems(items) {
  if (!Array.isArray(items) || items.length === 0) {
    throw new ValidationError('At least one item is required.');
  }

  const merged = new Map();
  for (const item of items) {
    const productId = Number(item.product_id);
    const quantity = Number(item.quantity);
    if (!Number.isInteger(productId) || productId <= 0) {
      throw new ValidationError('product_id must be a positive integer.');
    }
    if (!Number.isInteger(quantity) || quantity <= 0) {
      throw new ValidationError('quantity must be a positive integer.');
    }
    merged.set(productId, (merged.get(productId) || 0) + quantity);
  }

  return [...merged.entries()]
    .sort((a, b) => a[0] - b[0])
    .map(([productId, quantity]) => ({ productId, quantity }));
}

/** 1) GetStock: how many units are left. Unknown products have 0 units. */
async function getStock(productId) {
  const id = Number(productId);
  if (!Number.isInteger(id) || id <= 0) {
    throw new ValidationError('product_id must be a positive integer.');
  }
  const { rows } = await pool.query('SELECT quantity FROM stock WHERE product_id = $1', [id]);
  return rows.length ? rows[0].quantity : 0;
}

/**
 * 2) ReserveStock: all-or-nothing reservation for every item of one order.
 *
 * Race-condition safety: each item is taken with ONE atomic statement
 *   UPDATE stock SET quantity = quantity - n WHERE product_id = ? AND quantity >= n
 * PostgreSQL locks the row; a concurrent request for the same product waits,
 * then re-checks "quantity >= n" against the committed value. So when 10 requests
 * race for the last unit, exactly one UPDATE matches and the other 9 match 0 rows.
 */
async function reserve(reservationId, items, isCancelled = () => false) {
  validateReservationId(reservationId);
  const normalized = normalizeItems(items);

  const client = await pool.connect();
  try {
    await client.query('BEGIN');

    // Idempotency: the same reservation_id can only be used once.
    const inserted = await client.query(
      "INSERT INTO reservations (id, status) VALUES ($1, 'reserved') ON CONFLICT (id) DO NOTHING",
      [reservationId],
    );

    if (inserted.rowCount === 0) {
      const { rows } = await client.query('SELECT status FROM reservations WHERE id = $1', [reservationId]);
      await client.query('COMMIT');
      if (rows[0] && rows[0].status === 'reserved') {
        return { success: true, message: 'Already reserved (safe retry).', shortages: [] };
      }
      return { success: false, message: 'This reservation was already released.', shortages: [] };
    }

    const shortages = [];
    for (const { productId, quantity } of normalized) {
      const updated = await client.query(
        `UPDATE stock
            SET quantity = quantity - $2, updated_at = now()
          WHERE product_id = $1 AND quantity >= $2`,
        [productId, quantity],
      );

      if (updated.rowCount === 0) {
        const { rows } = await client.query('SELECT quantity FROM stock WHERE product_id = $1', [productId]);
        shortages.push({
          product_id: productId,
          requested: quantity,
          available: rows.length ? rows[0].quantity : 0,
        });
      }
    }

    if (shortages.length > 0) {
      await client.query('ROLLBACK');
      const list = shortages
        .map((s) => `product ${s.product_id} (requested ${s.requested}, available ${s.available})`)
        .join(', ');
      return { success: false, message: `Insufficient stock for ${list}.`, shortages };
    }

    for (const { productId, quantity } of normalized) {
      await client.query(
        'INSERT INTO reservation_items (reservation_id, product_id, quantity) VALUES ($1, $2, $3)',
        [reservationId, productId, quantity],
      );
    }

    // The caller already gave up (deadline passed): do not keep stock nobody will use.
    if (isCancelled()) {
      await client.query('ROLLBACK');
      return { success: false, message: 'Caller cancelled the request; nothing was reserved.', shortages: [] };
    }

    await client.query('COMMIT');
    return { success: true, message: 'Stock reserved.', shortages: [] };
  } catch (err) {
    await client.query('ROLLBACK').catch(() => {});
    throw err;
  } finally {
    client.release();
  }
}

/**
 * 3) ReleaseStock: give back everything a reservation took. Safe to call many times.
 * If the reservation does not exist yet, a "released" marker is stored, so a late
 * ReserveStock with the same id (e.g. one that arrived after the caller timed out)
 * is refused instead of silently taking stock.
 */
async function release(reservationId) {
  validateReservationId(reservationId);

  const client = await pool.connect();
  try {
    await client.query('BEGIN');

    const marker = await client.query(
      "INSERT INTO reservations (id, status, released_at) VALUES ($1, 'released', now()) ON CONFLICT (id) DO NOTHING",
      [reservationId],
    );
    if (marker.rowCount === 1) {
      await client.query('COMMIT');
      return { released: false, message: 'Nothing was reserved with this id.' };
    }

    const { rows } = await client.query('SELECT status FROM reservations WHERE id = $1 FOR UPDATE', [reservationId]);
    if (rows[0].status === 'released') {
      await client.query('COMMIT');
      return { released: false, message: 'Reservation was already released.' };
    }

    await client.query(
      `UPDATE stock s
          SET quantity = s.quantity + ri.quantity, updated_at = now()
         FROM reservation_items ri
        WHERE ri.reservation_id = $1 AND s.product_id = ri.product_id`,
      [reservationId],
    );
    await client.query(
      "UPDATE reservations SET status = 'released', released_at = now() WHERE id = $1",
      [reservationId],
    );

    await client.query('COMMIT');
    return { released: true, message: 'Reserved stock returned to the warehouse.' };
  } catch (err) {
    await client.query('ROLLBACK').catch(() => {});
    throw err;
  } finally {
    client.release();
  }
}

module.exports = { getStock, reserve, release, ValidationError };
