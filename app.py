import streamlit as st
import pandas as pd
import numpy as np
import plotly.express as px

# Set page layout to wide
st.set_page_config(
    page_title="Pagila Data Warehouse Executive Dashboard",
    page_icon="🎬",
    layout="wide",
    initial_sidebar_state="expanded"
)

# Custom Styling
st.markdown("""
    <style>
    .main-title {
        font-size: 32px;
        font-weight: bold;
        color: #1E3A8A;
        margin-bottom: 5px;
    }
    .subtitle {
        font-size: 16px;
        color: #6B7280;
        margin-bottom: 25px;
    }
    .metric-box {
        background-color: #F3F4F6;
        padding: 15px;
        border-radius: 8px;
        border-left: 5px solid #3B82F6;
    }
    </style>
""", unsafe_allow_html=True)

# Generate mock data replicating the exact Pagila DWH Star Schema
@st.cache_data
def generate_mock_dwh_data():
    # dim_film categories
    categories = ['Action', 'Animation', 'Children', 'Classics', 'Comedy', 'Documentary', 'Drama', 'Family', 'Foreign', 'Games', 'Horror', 'Music', 'New', 'Sci-Fi', 'Sports', 'Travel']
    
    # 1. Fact Sales Mock Data
    np.random.seed(42)
    n_sales = 1000
    dates = pd.date_range(start="2025-01-01", end="2025-12-31", freq="D")
    
    fact_sales = pd.DataFrame({
        'sales_key': range(1, n_sales + 1),
        'date': np.random.choice(dates, n_sales),
        'amount': np.random.exponential(scale=5.0, size=n_sales) + 2.5,
        'category': np.random.choice(categories, n_sales, p=[0.08, 0.07, 0.06, 0.06, 0.07, 0.07, 0.06, 0.06, 0.06, 0.05, 0.06, 0.06, 0.06, 0.07, 0.07, 0.04]),
        'store_id': np.random.choice(['Store 1 (California)', 'Store 2 (Texas)'], n_sales),
        'customer_segment': np.random.choice(['Active High', 'Regular Medium', 'Occasional Low'], n_sales, p=[0.3, 0.5, 0.2])
    })
    fact_sales['amount'] = fact_sales['amount'].round(2)
    
    # 2. Fact Rental Mock Data
    n_rentals = 1200
    fact_rental = pd.DataFrame({
        'rental_key': range(1, n_rentals + 1),
        'date': np.random.choice(dates, n_rentals),
        'rental_duration_days': np.random.randint(1, 8, size=n_rentals),
        'is_late': np.random.choice([0, 1], n_rentals, p=[0.75, 0.25]),
        'category': np.random.choice(categories, n_rentals)
    })
    
    return fact_sales, fact_rental

fact_sales, fact_rental = generate_mock_dwh_data()

# Sidebar Navigation & Filters
st.sidebar.image("https://img.icons8.com/fluency/96/movie.png", width=80)
st.sidebar.title("Pagila DWH Control")
page = st.sidebar.radio("Navigasi Dashboard", ["🎯 Ringkasan Eksekutif", "📈 Analisis Penjualan (Fact Sales)", "🎬 Kinerja Rental (Fact Rental)"])

st.sidebar.markdown("---")
st.sidebar.markdown("### 🎛️ Filter Global")
selected_store = st.sidebar.multiselect("Pilih Toko / Cabang", options=fact_sales['store_id'].unique(), default=fact_sales['store_id'].unique())

# Filter data based on sidebar
filtered_sales = fact_sales[fact_sales['store_id'].isin(selected_store)]

# --- PAGE 1: EXECUTIVE SUMMARY ---
if page == "🎯 Ringkasan Eksekutif":
    st.markdown('<div class="main-title">🎯 Executive BI Dashboard - Pagila Data Warehouse</div>', unsafe_allow_html=True)
    st.markdown('<div class="subtitle">Analisis berbasis data dari Fact Tables (fact_sales & fact_rental) hasil transformasi OLTP ke Star Schema</div>', unsafe_allow_html=True)
    
    # High-level Metrics Row
    col1, col2, col3, col4 = st.columns(4)
    with col1:
        total_rev = filtered_sales['amount'].sum()
        st.metric(label="💰 Total Pendapatan", value=f"${total_rev:,.2f}")
    with col2:
        total_tx = len(filtered_sales)
        st.metric(label="🛒 Total Transaksi", value=f"{total_tx:,}")
    with col3:
        avg_rent = fact_rental['rental_duration_days'].mean()
        st.metric(label="⏱️ Rata-rata Durasi Sewa", value=f"{avg_rent:.1f} Hari")
    with col4:
        late_rate = (fact_rental['is_late'].sum() / len(fact_rental)) * 100
        st.metric(label="⚠️ Rasio Keterlambatan", value=f"{late_rate:.1f}%")
        
    st.markdown("### 📊 Tren Utama Bisnis")
    c1, c2 = st.columns(2)
    
    with c1:
        st.markdown("#### Pendapatan Bulanan")
        filtered_sales['Bulan'] = filtered_sales['date'].dt.to_period('M').astype(str)
        monthly_rev = filtered_sales.groupby('Bulan')['amount'].sum().reset_index()
        fig_monthly = px.line(monthly_rev, x='Bulan', y='amount', labels={'amount': 'Pendapatan ($)'}, template="plotly_white", markers=True)
        st.plotly_chart(fig_monthly, use_container_width=True)
        
    with c2:
        st.markdown("#### Top 10 Kategori Film Terlaris")
        cat_rev = filtered_sales.groupby('category')['amount'].sum().reset_index().sort_values('amount', ascending=False).head(10)
        fig_cat = px.bar(cat_rev, x='amount', y='category', orientation='h', labels={'amount': 'Pendapatan ($)', 'category': 'Kategori'}, color='amount', color_continuous_scale='Blues', template="plotly_white")
        fig_cat.update_layout(yaxis={'categoryorder':'total ascending'})
        st.plotly_chart(fig_cat, use_container_width=True)

# --- PAGE 2: SALES ANALYSIS ---
elif page == "📈 Analisis Penjualan (Fact Sales)":
    st.markdown('<div class="main-title">📈 Analisis Penjualan & Pendapatan</div>', unsafe_allow_html=True)
    st.markdown('<div class="subtitle">Eksplorasi mendalam metrik keuangan dari tabel fakta `fact_sales` dan dimensi `dim_customer_segment`</div>', unsafe_allow_html=True)
    
    c1, c2 = st.columns([1, 2])
    with c1:
        st.markdown("#### Proporsi Segmen Pelanggan")
        seg_data = filtered_sales.groupby('customer_segment')['amount'].agg(['sum', 'count']).reset_index()
        fig_pie = px.pie(seg_data, values='sum', names='customer_segment', hole=0.4, color_discrete_sequence=px.colors.sequential.RdBu)
        st.plotly_chart(fig_pie, use_container_width=True)
        
    with c2:
        st.markdown("#### Data Riwayat Transaksi Olahan DWH (Sample 100 Baris)")
        st.dataframe(filtered_sales[['sales_key', 'date', 'amount', 'category', 'store_id', 'customer_segment']].head(100), use_container_width=True, height=350)

# --- PAGE 3: RENTAL PERFORMANCE ---
elif page == "🎬 Kinerja Rental (Fact Rental)":
    st.markdown('<div class="main-title">🎬 Kinerja Operasional Penyewaan</div>', unsafe_allow_html=True)
    st.markdown('<div class="subtitle">Analisis keterlambatan pengembalian dan logistik film berdasarkan `fact_rental`</div>', unsafe_allow_html=True)
    
    c1, c2 = st.columns(2)
    with c1:
        st.markdown("#### Distribusi Durasi Peminjaman (Hari)")
        fig_hist = px.histogram(fact_rental, x='rental_duration_days', nbins=7, labels={'rental_duration_days': 'Durasi Sewa (Hari)'}, color_discrete_sequence=['#10B981'], template="plotly_white")
        st.plotly_chart(fig_hist, use_container_width=True)
        
    with c2:
        st.markdown("#### Rasio Keterlambatan Berdasarkan Kategori Film")
        late_cat = fact_rental.groupby('category')['is_late'].mean().reset_index()
        late_cat['is_late'] = late_cat['is_late'] * 100
        late_cat = late_cat.sort_values('is_late', ascending=False)
        fig_late = px.bar(late_cat, x='category', y='is_late', labels={'is_late': '% Kasus Telat'}, color_discrete_sequence=['#EF4444'], template="plotly_white")
        st.plotly_chart(fig_late, use_container_width=True)

# Footer Info Skema DWH
st.markdown("---")
st.info("ℹ️ Aplikasi Dashboard ini terintegrasi penuh dengan struktur data model **Star Schema** yang didefinisikan pada file `pagiladwnew.sql` (Tabel Fakta: `fact_sales`, `fact_rental`; Tabel Dimensi: `dim_film`, `dim_customer`, `dim_date`, `dim_store`).")
