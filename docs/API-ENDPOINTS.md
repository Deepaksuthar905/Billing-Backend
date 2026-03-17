# Billing API – Endpoints & Call Paths

Base URL (local): `http://localhost:8888/billing/public` ya aapka domain  
API prefix: `/api`

---

## Table: Kaam → Method → Endpoint → Response shape

| Kaam | Method | Endpoint | Response shape (short) |
|------|--------|----------|------------------------|
| Dashboard | `GET` | `/api/dashboard` | `{ totalSales, totalPurchase, outstanding, invoiceCount, recentInvoices [], recentPurchases [] }` |
| Invoices list | `GET` | `/api/invoices?search=&status=` | `{ data: [ { id, date, customer, amount, gst, status } ] }` |
| Create invoice | `POST` | `/api/invoices` | Body: `customerId` (pid), items [], dueDate (optional) – backend uses existing fields |
| Sales list | `GET` | `/api/sales?search=` | `{ data: [ { id, date, customer, items, total, status } ] }` |
| Purchase list | `GET` | `/api/purchase-orders?search=` | `{ data: [ { id, date, vendor, amount, status } ] }` |
| Vendors list | `GET` | `/api/vendors?search=` | `{ data: [ { id, name, contact, balance, lastOrder } ] }` |
| Customers list | `GET` | `/api/customers?search=` | `{ data: [ { id, name, phone, email, gstin, balance, totalOrders } ] }` |
| Items list | `GET` | `/api/items?search=&status=` | `{ data: [], summary: { totalItems, lowStockCount, outOfStockCount } }` |

---

## Frontend se API call karne ka path (examples)

### 1. Dashboard

```javascript
// fetch
const res = await fetch('/api/dashboard');
const json = await res.json();

// axios
const { data } = await axios.get('/api/dashboard');
// data: { totalSales, totalPurchase, outstanding, invoiceCount, recentInvoices, recentPurchases }
```

**Full URL:** `GET {{base}}/api/dashboard`

---

### 2. Invoices list (search, status filter)

```javascript
const params = new URLSearchParams({ search: 'acme', status: 'pending' });
const res = await fetch(`/api/invoices?${params}`);
const json = await res.json();

// axios
const { data } = await axios.get('/api/invoices', { params: { search: 'acme', status: 'pending' } });
// data.data: [ { id, date, customer, amount, gst, status }, ... ]
```

**Full URL:** `GET {{base}}/api/invoices?search=&status=`

---

### 3. Create invoice

```javascript
const body = {
  pid: 1,           // customerId (party id)
  inv_no: 'INV-001',
  dt: '2025-03-16',
  payment: 1000,
  gst: 180,
  paytype: 1,
  paynow: 500,
  paylater: 500,
  balance: 500
  // + other fields as per InvoiceController store validation
};
const res = await fetch('/api/invoices', {
  method: 'POST',
  headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
  body: JSON.stringify(body)
});
const json = await res.json();

// axios
const { data } = await axios.post('/api/invoices', body);
```

**Full URL:** `POST {{base}}/api/invoices`

---

### 4. Sales list

```javascript
const res = await fetch('/api/sales?search=john');
const json = await res.json();

// axios
const { data } = await axios.get('/api/sales', { params: { search: 'john' } });
// data.data: [ { id, date, customer, items, total, status }, ... ]
```

**Full URL:** `GET {{base}}/api/sales?search=`

---

### 5. Purchase orders list

```javascript
const res = await fetch('/api/purchase-orders?search=supplier');
const json = await res.json();

// axios
const { data } = await axios.get('/api/purchase-orders', { params: { search: 'supplier' } });
// data.data: [ { id, date, vendor, amount, status }, ... ]
```

**Full URL:** `GET {{base}}/api/purchase-orders?search=`

---

### 6. Vendors list

```javascript
const res = await fetch('/api/vendors?search=abc');
const json = await res.json();

// axios
const { data } = await axios.get('/api/vendors', { params: { search: 'abc' } });
// data.data: [ { id, name, contact, balance, lastOrder }, ... ]
```

**Full URL:** `GET {{base}}/api/vendors?search=`

---

### 7. Customers list

```javascript
const res = await fetch('/api/customers?search=xyz');
const json = await res.json();

// axios
const { data } = await axios.get('/api/customers', { params: { search: 'xyz' } });
// data.data: [ { id, name, phone, email, gstin, balance, totalOrders }, ... ]
```

**Full URL:** `GET {{base}}/api/customers?search=`

---

### 8. Items list (search, summary)

```javascript
const res = await fetch('/api/items?search=widget&status=');
const json = await res.json();

// axios
const { data } = await axios.get('/api/items', { params: { search: 'widget', status: '' } });
// data: { data: [...], summary: { totalItems, lowStockCount, outOfStockCount } }
```

**Full URL:** `GET {{base}}/api/items?search=&status=`

---

## Quick reference – sab API call paths

| Kaam | Call path |
|------|-----------|
| Dashboard | `GET /api/dashboard` |
| Invoices list | `GET /api/invoices?search=&status=` |
| Create invoice | `POST /api/invoices` |
| Sales list | `GET /api/sales?search=` |
| Purchase list | `GET /api/purchase-orders?search=` |
| Vendors list | `GET /api/vendors?search=` |
| Customers list | `GET /api/customers?search=` |
| Items list | `GET /api/items?search=&status=` |

Frontend pe base URL set karo (e.g. `axios.defaults.baseURL = 'http://localhost:8888/billing/public'`) phir upar wale paths use kar sakte ho.
